<?php
// 一時デプロイスクリプト: アプリ本体を自社ドメインの公開領域へ配置する。
// 使い方: _deploy.php?token=<SETUP_TOKEN>
// 設置後は ?token=...&cleanup=1 で自身を削除すること。
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

if (!file_exists(__DIR__ . '/api/config.php')) { echo "config.php がありません\n"; exit; }
require __DIR__ . '/api/config.php';
if (!hash_equals(SETUP_TOKEN, (string)($_GET['token'] ?? ''))) {
  http_response_code(403); echo "合言葉が違います\n"; exit;
}

if (isset($_GET['cleanup'])) {
  @unlink(__FILE__);
  echo "デプロイスクリプトを削除しました\n";
  exit;
}

$base = 'https://raw.githubusercontent.com/yamamoto-coder/zairyou/main/';
$files = ['index.html', 'manifest.json', 'icon-192.png'];

// ALLOWED_ORIGIN を自社ドメインに更新(同一ドメイン運用に合わせる)
$cfg = file_get_contents(__DIR__ . '/api/config.php');
$new = preg_replace(
  "/define\('ALLOWED_ORIGIN',\s*'[^']*'\)/",
  "define('ALLOWED_ORIGIN', 'https://tonya.craftfile.jp')",
  $cfg, 1, $cnt
);
if ($cnt > 0 && $new !== $cfg) {
  file_put_contents(__DIR__ . '/api/config.php', $new);
  echo "OK  config.php の ALLOWED_ORIGIN を更新\n";
}

$ok = 0; $fail = 0;
foreach ($files as $f) {
  $url = $base . $f . '?t=' . time();
  $data = @file_get_contents($url);
  if ($data === false || $data === '') {
    if (function_exists('curl_init')) {
      $ch = curl_init($url);
      curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => true]);
      $data = curl_exec($ch);
      curl_close($ch);
    }
  }
  if ($data === false || $data === '') { echo "NG  $f (取得失敗)\n"; $fail++; continue; }
  if (@file_put_contents(__DIR__ . '/' . $f, $data) === false) { echo "NG  $f (書き込み失敗)\n"; $fail++; continue; }
  echo "OK  $f (" . strlen($data) . " bytes)\n"; $ok++;
}

// Xserver の初期ページ画像は不要
if (file_exists(__DIR__ . '/default_page.png')) { @unlink(__DIR__ . '/default_page.png'); echo "OK  初期ページ画像を削除\n"; }

echo "\n完了: 成功 $ok / 失敗 $fail\n";
echo $fail === 0 ? "https://tonya.craftfile.jp/ を開いて確認してください\n" : "失敗があります\n";
