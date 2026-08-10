<?php
// アプリデータの保存窓口(会社ごとのキー・バリュー)
// フロントの window.storage と 1:1 対応:
//   GET    storage.php?k=キー            → {value: 文字列 | null}
//   GET    storage.php?list=1            → {keys: [...]}(移行・確認用)
//   POST   storage.php {k, v}            → 保存
//   POST   storage.php {bulk: [{k,v}..]} → まとめて保存(ローカルからの移行用)
//   DELETE storage.php?k=キー            → 削除
declare(strict_types=1);
require __DIR__ . '/common.php';

$auth = require_auth();
$cid = $auth['company_id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// キーは英数と記号少々のみ許可(アプリが使う shizai-... 形式)
function valid_key(string $k): bool {
  return $k !== '' && strlen($k) <= 100 && preg_match('/^[a-zA-Z0-9._:\-]+$/', $k) === 1;
}

try {
  if ($method === 'GET') {
    if (($_GET['list'] ?? '') === '1') {
      $st = db()->prepare('SELECT k, LENGTH(v) AS bytes, updated_at FROM kv_data WHERE company_id = ? ORDER BY k');
      $st->execute([$cid]);
      respond(['keys' => $st->fetchAll()]);
    }
    $k = (string)($_GET['k'] ?? '');
    if (!valid_key($k)) respond(['error' => 'キーが不正です'], 400);
    $st = db()->prepare('SELECT v FROM kv_data WHERE company_id = ? AND k = ?');
    $st->execute([$cid, $k]);
    $row = $st->fetch();
    respond(['value' => $row ? $row['v'] : null]);
  }

  if ($method === 'POST') {
    $in = json_input();
    $items = [];
    if (isset($in['bulk']) && is_array($in['bulk'])) {
      foreach ($in['bulk'] as $item) {
        if (is_array($item)) $items[] = [(string)($item['k'] ?? ''), (string)($item['v'] ?? '')];
      }
    } else {
      $items[] = [(string)($in['k'] ?? ''), (string)($in['v'] ?? '')];
    }
    if (!$items) respond(['error' => '保存する内容がありません'], 400);
    $db = db();
    $st = $db->prepare(
      'INSERT INTO kv_data (company_id, k, v, updated_by) VALUES (?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE v = VALUES(v), updated_by = VALUES(updated_by)'
    );
    $db->beginTransaction();
    foreach ($items as [$k, $v]) {
      if (!valid_key($k)) { $db->rollBack(); respond(['error' => 'キーが不正です: ' . substr($k, 0, 30)], 400); }
      if (strlen($v) > 8 * 1024 * 1024) { $db->rollBack(); respond(['error' => 'データが大きすぎます: ' . $k], 413); }
      $st->execute([$cid, $k, $v, $auth['user_id']]);
    }
    $db->commit();
    respond(['ok' => true, 'saved' => count($items)]);
  }

  if ($method === 'DELETE') {
    $k = (string)($_GET['k'] ?? '');
    if (!valid_key($k)) respond(['error' => 'キーが不正です'], 400);
    $st = db()->prepare('DELETE FROM kv_data WHERE company_id = ? AND k = ?');
    $st->execute([$cid, $k]);
    respond(['ok' => true]);
  }

  respond(['error' => '不明な操作です'], 405);
} catch (Throwable $e) {
  error_log('[tonya-api storage] ' . $e->getMessage());
  respond(['error' => 'サーバーエラーが発生しました'], 500);
}
