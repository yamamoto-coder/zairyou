<?php
// AI 中継(プロキシ)
// APIキーはこのサーバーの config.php にだけ存在し、利用者のブラウザには渡らない。
//   POST ai.php {provider: "gemini"|"claude", body: {...}}
//     gemini: body は generateContent のリクエスト(contents, tools, generationConfig)
//     claude: body は messages API のリクエスト(model, max_tokens, messages, tools)
declare(strict_types=1);
require __DIR__ . '/common.php';

$auth = require_auth(); // ログイン者のみ(会社IDも取れるため将来の利用量集計に使える)

// 注意: ここは連想配列ではなくオブジェクトとして読む。
// 連想配列にすると空のオブジェクト {} が空の配列 [] に化けてしまい、
// Web検索の指定 "google_search": {} が Google 側で弾かれる。
$raw = file_get_contents('php://input');
$in = ($raw === '' || $raw === false) ? null : json_decode($raw);
if (!is_object($in)) respond(['error' => 'リクエストを読み取れませんでした'], 400);
$provider = isset($in->provider) ? (string)$in->provider : '';
$body = $in->body ?? null;
if (!is_object($body)) respond(['error' => 'リクエスト本文がありません'], 400);

// 中継先はこの2つだけ。任意URLへの転送は絶対にしない(SSRF対策)
try {
  if ($provider === 'gemini') {
    if (GEMINI_API_KEY === '') respond(['error' => 'サーバーに Gemini のAPIキーが設定されていません'], 503);
    $model = GEMINI_MODEL;
    if (isset($in->model) && preg_match('/^[a-zA-Z0-9.\-]+$/', (string)$in->model)) {
      $model = (string)$in->model;
    }
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
         . rawurlencode($model) . ':generateContent?key=' . rawurlencode(GEMINI_API_KEY);
    $headers = ['Content-Type: application/json'];
  } elseif ($provider === 'claude') {
    if (ANTHROPIC_API_KEY === '') respond(['error' => 'サーバーに Claude のAPIキーが設定されていません'], 503);
    $url = 'https://api.anthropic.com/v1/messages';
    $headers = [
      'Content-Type: application/json',
      'x-api-key: ' . ANTHROPIC_API_KEY,
      'anthropic-version: 2023-06-01',
    ];
    if (!isset($body->model)) $body->model = 'claude-sonnet-4-6';
    if (!isset($body->max_tokens)) $body->max_tokens = 4000;
  } else {
    respond(['error' => 'provider は gemini か claude を指定してください'], 400);
  }

  $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
  if ($payload === false || strlen($payload) > 60 * 1024 * 1024) {
    respond(['error' => 'リクエストが大きすぎます'], 413);
  }

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 300,
    CURLOPT_CONNECTTIMEOUT => 15,
  ]);
  $res = curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $err = curl_error($ch);
  curl_close($ch);

  if ($res === false) {
    error_log('[tonya-api ai] curl: ' . $err);
    respond(['error' => 'AIサービスへの接続に失敗しました'], 502);
  }
  http_response_code($status ?: 200);
  header('Content-Type: application/json; charset=utf-8');
  header('X-Content-Type-Options: nosniff');
  echo $res; // AI 側の応答をそのまま返す(キーは含まれない)
  exit;
} catch (Throwable $e) {
  error_log('[tonya-api ai] ' . $e->getMessage());
  respond(['error' => 'サーバーエラーが発生しました'], 500);
}
