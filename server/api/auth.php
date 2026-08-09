<?php
// ログイン・会社/ユーザー管理
// POST auth.php {action: "setup"|"login"|"logout"|"me"|"add_user"|"change_password", ...}
declare(strict_types=1);
require __DIR__ . '/common.php';

$in = json_input();
$action = $in['action'] ?? '';

try {
  switch ($action) {

    // 初期設定: 会社と最初の管理者を作る(合言葉 SETUP_TOKEN が必要)
    case 'setup': {
      if (!hash_equals(SETUP_TOKEN, (string)($in['setup_token'] ?? ''))) {
        respond(['error' => '合言葉が違います'], 403);
      }
      $company = trim((string)($in['company'] ?? ''));
      $email = strtolower(trim((string)($in['email'] ?? '')));
      $password = (string)($in['password'] ?? '');
      if ($company === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
        respond(['error' => '会社名・メールアドレス・パスワード(8文字以上)を確認してください'], 400);
      }
      $db = db();
      $st = $db->prepare('SELECT id FROM users WHERE email = ?');
      $st->execute([$email]);
      if ($st->fetch()) respond(['error' => 'このメールアドレスは登録済みです'], 409);
      $db->beginTransaction();
      $db->prepare('INSERT INTO companies (name) VALUES (?)')->execute([$company]);
      $companyId = (int)$db->lastInsertId();
      $db->prepare('INSERT INTO users (company_id, email, pass_hash, role) VALUES (?, ?, ?, "admin")')
         ->execute([$companyId, $email, password_hash($password, PASSWORD_DEFAULT)]);
      $db->commit();
      respond(['ok' => true, 'company_id' => $companyId]);
    }

    case 'login': {
      check_login_rate();
      $email = strtolower(trim((string)($in['email'] ?? '')));
      $password = (string)($in['password'] ?? '');
      $st = db()->prepare('SELECT u.id, u.company_id, u.pass_hash, u.role, c.name AS company_name FROM users u JOIN companies c ON c.id = u.company_id WHERE u.email = ?');
      $st->execute([$email]);
      $u = $st->fetch();
      if (!$u || !password_verify($password, $u['pass_hash'])) {
        record_login_failure();
        respond(['error' => 'メールアドレスまたはパスワードが違います'], 401);
      }
      $token = bin2hex(random_bytes(32));
      $st = db()->prepare('INSERT INTO tokens (token, user_id, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ' . (int)TOKEN_DAYS . ' DAY))');
      $st->execute([$token, (int)$u['id']]);
      respond(['ok' => true, 'token' => $token, 'email' => $email, 'role' => $u['role'], 'company' => $u['company_name']]);
    }

    case 'logout': {
      $token = bearer_token();
      if ($token !== '') {
        $st = db()->prepare('DELETE FROM tokens WHERE token = ?');
        $st->execute([$token]);
      }
      respond(['ok' => true]);
    }

    case 'me': {
      $auth = require_auth();
      $st = db()->prepare('SELECT name FROM companies WHERE id = ?');
      $st->execute([$auth['company_id']]);
      $c = $st->fetch();
      respond(['ok' => true, 'email' => $auth['email'], 'role' => $auth['role'], 'company' => $c ? $c['name'] : '']);
    }

    // 同じ会社にユーザーを追加(管理者のみ)
    case 'add_user': {
      $auth = require_auth();
      if ($auth['role'] !== 'admin') respond(['error' => '管理者のみ操作できます'], 403);
      $email = strtolower(trim((string)($in['email'] ?? '')));
      $password = (string)($in['password'] ?? '');
      $role = ($in['role'] ?? 'member') === 'admin' ? 'admin' : 'member';
      if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
        respond(['error' => 'メールアドレスとパスワード(8文字以上)を確認してください'], 400);
      }
      $st = db()->prepare('SELECT id FROM users WHERE email = ?');
      $st->execute([$email]);
      if ($st->fetch()) respond(['error' => 'このメールアドレスは登録済みです'], 409);
      db()->prepare('INSERT INTO users (company_id, email, pass_hash, role) VALUES (?, ?, ?, ?)')
          ->execute([$auth['company_id'], $email, password_hash($password, PASSWORD_DEFAULT), $role]);
      respond(['ok' => true]);
    }

    case 'change_password': {
      $auth = require_auth();
      $current = (string)($in['current'] ?? '');
      $new = (string)($in['new'] ?? '');
      if (strlen($new) < 8) respond(['error' => '新しいパスワードは8文字以上にしてください'], 400);
      $st = db()->prepare('SELECT pass_hash FROM users WHERE id = ?');
      $st->execute([$auth['user_id']]);
      $u = $st->fetch();
      if (!$u || !password_verify($current, $u['pass_hash'])) {
        respond(['error' => '現在のパスワードが違います'], 401);
      }
      db()->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')
          ->execute([password_hash($new, PASSWORD_DEFAULT), $auth['user_id']]);
      respond(['ok' => true]);
    }

    default:
      respond(['error' => '不明な操作です'], 400);
  }
} catch (Throwable $e) {
  error_log('[tonya-api auth] ' . $e->getMessage());
  respond(['error' => 'サーバーエラーが発生しました'], 500);
}
