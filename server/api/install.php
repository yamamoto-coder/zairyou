<?php
// ============================================================
// 一時的な初期設置スクリプト(テーブル作成)
//
// 使い方: config.php を設置した後、ブラウザで
//   install.php?token=<config.php の SETUP_TOKEN>
// を1回開くと、schema.sql のテーブルを作成します。
// ★設置が終わったらこのファイルは削除してください。
// ============================================================
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// config.php が無い場合、?mkconfig=1 で見本から雛形を作る(値は後で手入力)
if (!file_exists(__DIR__ . '/config.php')) {
  if (isset($_GET['mkconfig'])) {
    $tpl = @file_get_contents(__DIR__ . '/config.sample.php');
    if ($tpl === false) {
      http_response_code(500);
      echo json_encode(['error' => 'config.sample.php がありません'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $ok = @file_put_contents(__DIR__ . '/config.php', $tpl);
    echo json_encode(
      $ok !== false
        ? ['ok' => true, 'next' => 'config.php を作成しました。ファイルマネージャで開いて値を入力してください']
        : ['error' => 'config.php の作成に失敗しました(書き込み権限を確認してください)'],
      JSON_UNESCAPED_UNICODE
    );
    exit;
  }
  http_response_code(500);
  echo json_encode(['error' => 'config.php がありません。?mkconfig=1 を付けると雛形を作成します'], JSON_UNESCAPED_UNICODE);
  exit;
}
require __DIR__ . '/config.php';

if (!hash_equals(SETUP_TOKEN, (string)($_GET['token'] ?? ''))) {
  http_response_code(403);
  echo json_encode(['error' => '合言葉(token)が違います']);
  exit;
}

$schemaPath = __DIR__ . '/../schema.sql';
if (!file_exists($schemaPath)) {
  http_response_code(500);
  echo json_encode(['error' => 'schema.sql が見つかりません(tonya-api/schema.sql に置いてください)']);
  exit;
}

try {
  $pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
  );
  $sql = file_get_contents($schemaPath);
  // コメント行を除去し、セミコロンで区切って順に実行
  $sql = preg_replace('/^\s*--.*$/m', '', $sql);
  $ran = 0;
  foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    $pdo->exec($stmt);
    $ran++;
  }
  $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
  echo json_encode([
    'ok' => true,
    'executed' => $ran,
    'tables' => $tables,
    'next' => 'テーブル作成が完了しました。このファイル(install.php)を削除してください',
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  // 設置作業中のみ使うファイルなので、原因特定のためエラー内容を表示する
  echo json_encode(['error' => '失敗: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
