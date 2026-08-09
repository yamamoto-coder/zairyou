<?php
// 一時ブートストラップ: 公開リポジトリから API 一式を取得して配置する。
// 設置後は削除すること。config.php(秘密情報)は含まれない。
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

$base = 'https://raw.githubusercontent.com/yamamoto-coder/zairyou/main/server/';
$targets = [
  'api/common.php'        => __DIR__ . '/common.php',
  'api/auth.php'          => __DIR__ . '/auth.php',
  'api/storage.php'       => __DIR__ . '/storage.php',
  'api/docs.php'          => __DIR__ . '/docs.php',
  'api/ai.php'            => __DIR__ . '/ai.php',
  'api/install.php'       => __DIR__ . '/install.php',
  'api/config.sample.php' => __DIR__ . '/config.sample.php',
  'api/.htaccess'         => __DIR__ . '/.htaccess',
  'schema.sql'            => dirname(__DIR__) . '/schema.sql',
  'files/.htaccess'       => dirname(__DIR__) . '/files/.htaccess',
];

$ok = 0; $fail = 0;
foreach ($targets as $remote => $local) {
  $url = $base . $remote;
  $data = @file_get_contents($url);
  if ($data === false || $data === '') {
    // cURL でも試す
    if (function_exists('curl_init')) {
      $ch = curl_init($url);
      curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => true]);
      $data = curl_exec($ch);
      curl_close($ch);
    }
  }
  if ($data === false || $data === '') {
    echo "NG  $remote (取得失敗)\n"; $fail++; continue;
  }
  $dir = dirname($local);
  if (!is_dir($dir)) @mkdir($dir, 0705, true);
  if (@file_put_contents($local, $data) === false) {
    echo "NG  $remote (書き込み失敗)\n"; $fail++; continue;
  }
  echo "OK  $remote -> " . basename($local) . " (" . strlen($data) . " bytes)\n"; $ok++;
}

echo "\n完了: 成功 $ok / 失敗 $fail\n";
echo $fail === 0
  ? "次: config.php を作成し、install.php でテーブルを作成してください。その後 _boot.php を削除してください。\n"
  : "失敗があります。サーバーが外部へ接続できない可能性があります。\n";
