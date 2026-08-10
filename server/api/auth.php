<?php
// ログイン・会社/ユーザー管理・新規登録(メール認証)
// POST auth.php {action: "setup"|"login"|"logout"|"me"|"add_user"|"change_password"
//                        |"signup_start"|"signup_verify"|"signup_resend", ...}
declare(strict_types=1);
require __DIR__ . '/common.php';

$in = json_input();
$action = $in['action'] ?? '';

// 新規登録の申込を一時的に預かる表(無ければ作る)
function ensure_signup_table(): void {
  db()->exec("CREATE TABLE IF NOT EXISTS signups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token CHAR(64) NOT NULL UNIQUE,
    company VARCHAR(100) NOT NULL,
    email VARCHAR(190) NOT NULL,
    pass_hash VARCHAR(255) NOT NULL,
    code CHAR(6) NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    resends INT NOT NULL DEFAULT 0,
    ip VARCHAR(45) NOT NULL DEFAULT '',
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
}

// 確認コードのメールを送る
function send_signup_mail(string $email, string $code): bool {
  mb_language('ja');
  mb_internal_encoding('UTF-8');
  $subject = '【問屋さん】メールアドレス確認コード';
  $body = "問屋さんへの新規登録ありがとうございます。\n\n"
        . "確認コード: {$code}\n\n"
        . "登録画面にこの6桁のコードを入力してください。\n"
        . "コードの有効期限は20分です。\n\n"
        . "※心当たりがない場合は、このメールは破棄してください。\n\n"
        . "問屋さん — 建築資材検索システム\nhttps://tonyasan.jp/";
  return @mb_send_mail($email, $subject, $body,
    "From: noreply@tonyasan.jp\r\nReply-To: noreply@tonyasan.jp");
}

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

    // 新規登録 ①: 入力を預かり、確認コードをメールで送る
    case 'signup_start': {
      ensure_signup_table();
      $company = trim((string)($in['company'] ?? ''));
      $email = strtolower(trim((string)($in['email'] ?? '')));
      $password = (string)($in['password'] ?? '');
      if ($company === '' || mb_strlen($company) > 50) {
        respond(['error' => '会社名を入力してください(50文字以内)'], 400);
      }
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(['error' => 'メールアドレスの形式が正しくありません'], 400);
      }
      if (strlen($password) < 8) {
        respond(['error' => 'パスワードは8文字以上にしてください'], 400);
      }
      $db = db();
      $st = $db->prepare('SELECT id FROM users WHERE email = ?');
      $st->execute([$email]);
      if ($st->fetch()) {
        respond(['error' => 'このメールアドレスは登録済みです。ログイン画面からログインしてください'], 409);
      }
      // 申込の乱発を防ぐ(同じ接続元から1時間に5件まで)
      $st = $db->prepare('SELECT COUNT(*) AS c FROM signups WHERE ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)');
      $st->execute([client_ip()]);
      if ((int)$st->fetch()['c'] >= 5) {
        respond(['error' => '申込が集中しています。しばらく待ってからお試しください'], 429);
      }
      $db->prepare('DELETE FROM signups WHERE email = ?')->execute([$email]);
      $token = bin2hex(random_bytes(32));
      $code = sprintf('%06d', random_int(0, 999999));
      $db->prepare('INSERT INTO signups (token, company, email, pass_hash, code, ip, expires_at)
                    VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 20 MINUTE))')
         ->execute([$token, $company, $email, password_hash($password, PASSWORD_DEFAULT), $code, client_ip()]);
      if (!send_signup_mail($email, $code)) {
        respond(['error' => '確認メールを送信できませんでした。メールアドレスをご確認ください'], 500);
      }
      respond(['ok' => true, 'signup_token' => $token, 'email' => $email]);
    }

    // 新規登録 ②: 確認コードを照合し、会社と管理者アカウントを作成してログイン
    case 'signup_verify': {
      ensure_signup_table();
      $token = (string)($in['signup_token'] ?? '');
      $code = trim((string)($in['code'] ?? ''));
      $db = db();
      $st = $db->prepare('SELECT * FROM signups WHERE token = ?');
      $st->execute([$token]);
      $s = $st->fetch();
      if (!$s) respond(['error' => '登録情報が見つかりません。最初からやり直してください'], 400);
      if (strtotime($s['expires_at']) < time()) {
        $db->prepare('DELETE FROM signups WHERE id = ?')->execute([(int)$s['id']]);
        respond(['error' => '確認コードの有効期限が切れました。最初からやり直してください'], 400);
      }
      if ((int)$s['attempts'] >= 5) {
        $db->prepare('DELETE FROM signups WHERE id = ?')->execute([(int)$s['id']]);
        respond(['error' => '間違いが多いため無効になりました。最初からやり直してください'], 400);
      }
      if (!hash_equals($s['code'], $code)) {
        $db->prepare('UPDATE signups SET attempts = attempts + 1 WHERE id = ?')->execute([(int)$s['id']]);
        $left = 5 - (int)$s['attempts'] - 1;
        respond(['error' => '確認コードが違います(あと' . max($left, 0) . '回入力できます)'], 400);
      }
      // 登録済みでないことを再確認してから作成
      $st = $db->prepare('SELECT id FROM users WHERE email = ?');
      $st->execute([$s['email']]);
      if ($st->fetch()) respond(['error' => 'このメールアドレスは登録済みです。ログインしてください'], 409);
      $db->beginTransaction();
      $db->prepare('INSERT INTO companies (name) VALUES (?)')->execute([$s['company']]);
      $companyId = (int)$db->lastInsertId();
      $db->prepare('INSERT INTO users (company_id, email, pass_hash, role) VALUES (?, ?, ?, "admin")')
         ->execute([$companyId, $s['email'], $s['pass_hash']]);
      $userId = (int)$db->lastInsertId();
      $db->prepare('DELETE FROM signups WHERE id = ?')->execute([(int)$s['id']]);
      $authToken = bin2hex(random_bytes(32));
      $db->prepare('INSERT INTO tokens (token, user_id, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ' . (int)TOKEN_DAYS . ' DAY))')
         ->execute([$authToken, $userId]);
      $db->commit();
      respond(['ok' => true, 'token' => $authToken, 'email' => $s['email'], 'role' => 'admin', 'company' => $s['company']]);
    }

    // 新規登録: 確認コードの再送(3回まで)
    case 'signup_resend': {
      ensure_signup_table();
      $token = (string)($in['signup_token'] ?? '');
      $db = db();
      $st = $db->prepare('SELECT * FROM signups WHERE token = ?');
      $st->execute([$token]);
      $s = $st->fetch();
      if (!$s) respond(['error' => '登録情報が見つかりません。最初からやり直してください'], 400);
      if ((int)$s['resends'] >= 3) respond(['error' => '再送は3回までです。最初からやり直してください'], 429);
      $db->prepare('UPDATE signups SET resends = resends + 1, expires_at = DATE_ADD(NOW(), INTERVAL 20 MINUTE) WHERE id = ?')
         ->execute([(int)$s['id']]);
      if (!send_signup_mail($s['email'], $s['code'])) {
        respond(['error' => '確認メールを送信できませんでした'], 500);
      }
      respond(['ok' => true]);
    }

    // 動作確認用: 合言葉(SETUP_TOKEN)を知る管理者だけがコードを照会できる
    case 'signup_peek': {
      if (!hash_equals(SETUP_TOKEN, (string)($in['setup_token'] ?? ''))) {
        respond(['error' => '合言葉が違います'], 403);
      }
      ensure_signup_table();
      $st = db()->prepare('SELECT code, expires_at FROM signups WHERE email = ? ORDER BY id DESC LIMIT 1');
      $st->execute([strtolower(trim((string)($in['email'] ?? '')))]);
      respond(['row' => $st->fetch() ?: null]);
    }

    default:
      respond(['error' => '不明な操作です'], 400);
  }
} catch (Throwable $e) {
  error_log('[tonya-api auth] ' . $e->getMessage());
  respond(['error' => 'サーバーエラーが発生しました'], 500);
}
