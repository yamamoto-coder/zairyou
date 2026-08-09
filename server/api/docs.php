<?php
// 取り込んだ書類の原本(PDF/写真)の保存・閲覧・削除
//   POST   docs.php (multipart: file, doc_id, invoice_id, name, mime, hash) → 保存
//   GET    docs.php?doc=<doc_id>       → ファイル本体を返す(ログイン必須)
//   GET    docs.php?invoice=<id>       → その書類の原本一覧(JSON)
//   GET    docs.php?hash=<sha256>      → 同じ内容のファイルが登録済みか(重複取込防止)
//   DELETE docs.php?invoice=<id>       → その書類の原本を全削除
declare(strict_types=1);
require __DIR__ . '/common.php';

$auth = require_auth();
$cid = $auth['company_id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$allowedMimes = [
  'application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif',
];

try {
  if ($method === 'POST') {
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
      respond(['error' => 'ファイルを受け取れませんでした'], 400);
    }
    $f = $_FILES['file'];
    if ($f['size'] > MAX_UPLOAD_BYTES) respond(['error' => 'ファイルが大きすぎます(上限40MB)'], 413);
    $docId = substr((string)($_POST['doc_id'] ?? ''), 0, 190);
    $invoiceId = substr((string)($_POST['invoice_id'] ?? ''), 0, 190);
    $name = substr((string)($_POST['name'] ?? $f['name']), 0, 255);
    $mime = substr((string)($_POST['mime'] ?? $f['type']), 0, 100);
    $hash = strtolower(substr((string)($_POST['hash'] ?? ''), 0, 64));
    if ($docId === '' || $invoiceId === '') respond(['error' => 'doc_id と invoice_id が必要です'], 400);
    if (!in_array($mime, $allowedMimes, true)) respond(['error' => '対応していないファイル形式です'], 415);
    if ($hash !== '' && preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) $hash = '';
    // 会社ごとのフォルダに、推測できないファイル名で保存
    $dir = rtrim(FILES_DIR, '/') . '/' . $cid;
    if (!is_dir($dir)) mkdir($dir, 0705, true);
    $fname = bin2hex(random_bytes(16)) . '.bin';
    $path = $dir . '/' . $fname;
    if (!move_uploaded_file($f['tmp_name'], $path)) {
      respond(['error' => 'ファイルの保存に失敗しました'], 500);
    }
    $st = db()->prepare(
      'INSERT INTO documents (company_id, doc_id, invoice_id, name, mime, hash, size, path)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE invoice_id = VALUES(invoice_id), name = VALUES(name)'
    );
    $st->execute([$cid, $docId, $invoiceId, $name, $mime, $hash, (int)$f['size'], $cid . '/' . $fname]);
    respond(['ok' => true]);
  }

  if ($method === 'GET' && isset($_GET['doc'])) {
    $st = db()->prepare('SELECT name, mime, path, size FROM documents WHERE company_id = ? AND doc_id = ?');
    $st->execute([$cid, (string)$_GET['doc']]);
    $d = $st->fetch();
    if (!$d) respond(['error' => '見つかりません'], 404);
    $full = rtrim(FILES_DIR, '/') . '/' . $d['path'];
    // パスの逸脱防止(念のため実体パスを検証)
    $real = realpath($full);
    $base = realpath(FILES_DIR);
    if ($real === false || $base === false || strpos($real, $base) !== 0) {
      respond(['error' => 'ファイルがありません'], 404);
    }
    header('Content-Type: ' . $d['mime']);
    header('Content-Length: ' . (string)filesize($real));
    header('Content-Disposition: inline; filename*=UTF-8\'\'' . rawurlencode($d['name']));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=300');
    readfile($real);
    exit;
  }

  if ($method === 'GET' && isset($_GET['invoice'])) {
    $st = db()->prepare('SELECT doc_id, name, mime, size, added_at FROM documents WHERE company_id = ? AND invoice_id = ? ORDER BY added_at');
    $st->execute([$cid, (string)$_GET['invoice']]);
    respond(['docs' => $st->fetchAll()]);
  }

  if ($method === 'GET' && isset($_GET['hash'])) {
    $h = strtolower((string)$_GET['hash']);
    if (preg_match('/^[a-f0-9]{64}$/', $h) !== 1) respond(['error' => 'ハッシュが不正です'], 400);
    $st = db()->prepare('SELECT doc_id FROM documents WHERE company_id = ? AND hash = ? LIMIT 1');
    $st->execute([$cid, $h]);
    $row = $st->fetch();
    respond(['exists' => (bool)$row, 'doc_id' => $row ? $row['doc_id'] : null]);
  }

  if ($method === 'DELETE' && isset($_GET['invoice'])) {
    $st = db()->prepare('SELECT id, path FROM documents WHERE company_id = ? AND invoice_id = ?');
    $st->execute([$cid, (string)$_GET['invoice']]);
    $rows = $st->fetchAll();
    $del = db()->prepare('DELETE FROM documents WHERE company_id = ? AND id = ?');
    foreach ($rows as $r) {
      $full = rtrim(FILES_DIR, '/') . '/' . $r['path'];
      $real = realpath($full);
      $base = realpath(FILES_DIR);
      if ($real !== false && $base !== false && strpos($real, $base) === 0) @unlink($real);
      $del->execute([$cid, (int)$r['id']]);
    }
    respond(['ok' => true, 'deleted' => count($rows)]);
  }

  respond(['error' => '不明な操作です'], 405);
} catch (Throwable $e) {
  error_log('[tonya-api docs] ' . $e->getMessage());
  respond(['error' => 'サーバーエラーが発生しました'], 500);
}
