<?php
/*
 * Luno – kleiner Server-Vermittler (Proxy)
 * Hält die API-Schlüssel geheim. Die Luno-App fragt hier an, dieses Skript fragt Claude bzw. die Stimme an.
 *
 * EINRICHTEN: Nur die Werte im Block "EINSTELLUNGEN" ausfüllen, Datei hochladen, fertig.
 */

// ===================== EINSTELLUNGEN =====================
$ANTHROPIC_KEY  = 'HIER-ANTHROPIC-SCHLUESSEL-EINFUEGEN';   // sk-ant-...
$GEMINI_KEY     = '';   // empfohlen: Google-Stimme "Leda" (Schlüssel von aistudio.google.com/apikey)
$GEMINI_MODEL   = 'gemini-3.8-flash-tts';
$GEMINI_VOICE   = 'Leda';
$GEMINI_STYLE   = 'Sprich wie ein fröhliches sechsjähriges Kind: hohe, helle Stimme, lebendig, neugierig und verspielt. Natürliches Hochdeutsch.';
$OPENAI_KEY     = '';   // optional, Alternative zu Google (sk-...)
$ELEVEN_KEY     = '';   // optional, für ElevenLabs-Stimme
$ELEVEN_VOICE   = '';   // optional, ElevenLabs Voice-ID
$ACCESS_CODE    = 'HIER-EIGENEN-CODE-WAEHLEN';             // z. B. sternenstaub-4821 (steht später im Familien-Link)
$ALLOWED_ORIGIN = 'https://amjadm82-cloud.github.io';
$MAX_PER_HOUR   = 200;  // Schutz: höchstens so viele Anfragen pro Stunde und Gerät
// =========================================================

header('Access-Control-Allow-Origin: ' . $ALLOWED_ORIGIN);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Luno-Code');
header('Access-Control-Max-Age: 86400');
header('Vary: Origin');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function fail($code, $msg) { http_response_code($code); header('Content-Type: application/json'); echo json_encode(['error' => $msg]); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'POST only');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== $ALLOWED_ORIGIN) fail(403, 'origin');
if (!hash_equals($ACCESS_CODE, (string)($_SERVER['HTTP_X_LUNO_CODE'] ?? ''))) fail(401, 'code');

// einfache Begrenzung pro IP
$ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
$rl = sys_get_temp_dir() . '/luno_rl_' . md5($ip . date('YmdH'));
$n = (int)@file_get_contents($rl) + 1; @file_put_contents($rl, (string)$n);
if ($n > $MAX_PER_HOUR) fail(429, 'rate');

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) fail(400, 'json');
$action = $_GET['action'] ?? '';

function post($url, $headers, $body) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
    CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body]);
  $res = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  return [$status ?: 502, $res];
}

// sucht in einer API-Antwort das längste base64-Audiofeld ("data")
function find_audio($node, &$best) {
  if (is_array($node)) {
    foreach ($node as $k => $v) {
      if ($k === 'data' && is_string($v) && strlen($v) > strlen($best)) $best = $v;
      else find_audio($v, $best);
    }
  }
}
function pcm_to_wav($pcm, $rate = 24000) {
  $len = strlen($pcm);
  return 'RIFF' . pack('V', 36 + $len) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16) . 'data' . pack('V', $len) . $pcm;
}
function gemini_tts($text, $key, $model, $voice, $style) {
  // 1) aktuelle Interactions-API
  [$st, $res] = post('https://generativelanguage.googleapis.com/v1beta/interactions',
    ['x-goog-api-key: ' . $key, 'Content-Type: application/json'],
    json_encode(['model' => $model,
      'input' => [['type' => 'user_input', 'content' => [['type' => 'text', 'text' => $text,
        'annotations' => [['type' => 'speech_metadata', 'style' => $style]]]]]],
      'response_format' => ['type' => 'audio', 'mime_type' => 'audio/wav'],
      'generation_config' => ['speech_config' => [['voice' => $voice]]]]));
  if ($st === 200) {
    $b = ''; find_audio(json_decode($res, true), $b);
    if ($b !== '') { $a = base64_decode($b); return substr($a, 0, 4) === 'RIFF' ? $a : pcm_to_wav($a); }
  }
  // 2) Rückfall: generateContent mit dem Preview-Modell
  [$st, $res] = post('https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-tts-preview:generateContent',
    ['x-goog-api-key: ' . $key, 'Content-Type: application/json'],
    json_encode(['contents' => [['parts' => [['text' => $style . "\n\nSag genau diesen Text:\n" . $text]]]],
      'generationConfig' => ['responseModalities' => ['AUDIO'],
        'speechConfig' => ['voiceConfig' => ['prebuiltVoiceConfig' => ['voiceName' => $voice]]]]]));
  if ($st === 200) {
    $b = ''; find_audio(json_decode($res, true), $b);
    if ($b !== '') { $a = base64_decode($b); return substr($a, 0, 4) === 'RIFF' ? $a : pcm_to_wav($a); }
  }
  return null;
}

if ($action === 'chat') {
  $model = in_array($in['model'] ?? '', ['claude-haiku-5-5', 'claude-sonnet-5-5'], true) ? $in['model'] : 'claude-haiku-5-5';
  $system = mb_substr((string)($in['system'] ?? ''), 0, 6000);
  $msgs = [];
  foreach (array_slice((array)($in['messages'] ?? []), -16) as $m) {
    if (!in_array($m['role'] ?? '', ['user', 'assistant'], true)) continue;
    $msgs[] = ['role' => $m['role'], 'content' => mb_substr((string)$m['content'], 0, 2000)];
  }
  if (!$msgs) fail(400, 'messages');
  [$st, $res] = post('https://api.anthropic.com/v1/messages',
    ['x-api-key: ' . $ANTHROPIC_KEY, 'anthropic-version: 2023-06-01', 'content-type: application/json'],
    json_encode(['model' => $model, 'max_tokens' => 500, 'system' => $system, 'messages' => $msgs]));
  http_response_code($st); header('Content-Type: application/json'); echo $res; exit;
}

if ($action === 'tts') {
  $text = mb_substr(trim((string)($in['text'] ?? '')), 0, 600);
  if ($text === '') fail(400, 'text');
  $provider = $in['provider'] ?? '';
  if ($GEMINI_KEY && $provider !== 'openai' && $provider !== 'eleven') {
    $wav = gemini_tts($text, $GEMINI_KEY, $GEMINI_MODEL, $GEMINI_VOICE, $GEMINI_STYLE);
    if ($wav !== null) { header('Content-Type: audio/wav'); echo $wav; exit; }
    if (!$OPENAI_KEY) fail(502, 'gemini tts');
    $provider = 'openai';
  }
  if ($provider === 'eleven' && $ELEVEN_KEY && $ELEVEN_VOICE) {
    [$st, $res] = post('https://api.elevenlabs.io/v1/text-to-speech/' . rawurlencode($ELEVEN_VOICE) . '?output_format=mp3_44100_128',
      ['xi-api-key: ' . $ELEVEN_KEY, 'Content-Type: application/json'],
      json_encode(['text' => $text, 'model_id' => 'eleven_multilingual_v2', 'language_code' => 'de',
        'voice_settings' => ['stability' => 0.45, 'similarity_boost' => 0.8, 'style' => 0.35]]));
  } elseif ($OPENAI_KEY) {
    $voice = in_array($in['voice'] ?? '', ['coral', 'nova', 'shimmer', 'fable', 'echo'], true) ? $in['voice'] : 'coral';
    [$st, $res] = post('https://api.openai.com/v1/audio/speech',
      ['Authorization: Bearer ' . $OPENAI_KEY, 'Content-Type: application/json'],
      json_encode(['model' => 'gpt-4o-mini-tts', 'voice' => $voice, 'input' => $text, 'response_format' => 'mp3',
        'instructions' => 'Sprich natürliches Hochdeutsch mit perfekter deutscher Aussprache, ohne englischen Akzent. Du bist ein fröhliches, neugieriges Kind aus einer Zeichentrickserie: warm, lebendig, verspielt.']));
  } else fail(404, 'no tts');
  if ($st !== 200) fail($st, 'tts upstream');
  header('Content-Type: audio/mpeg'); echo $res; exit;
}

if ($action === 'info') {
  header('Content-Type: application/json');
  echo json_encode(['ok' => true, 'tts' => $GEMINI_KEY ? 'gemini' : (($ELEVEN_KEY && $ELEVEN_VOICE) ? 'eleven' : ($OPENAI_KEY ? 'openai' : 'browser'))]); exit;
}

fail(404, 'action');
