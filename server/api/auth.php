<?php
// ログイン・会社/ユーザー管理・新規登録(メール認証)
// POST auth.php {action: "setup"|"login"|"logout"|"me"|"add_user"|"change_password"
//                        |"signup_start"|"signup_verify"|"signup_resend", ...}
declare(strict_types=1);
require __DIR__ . '/common.php';

$in = json_input();
$action = $in['action'] ?? '';

// 運営会社の判定: config.php の OWNER_EMAILS のユーザーが属する会社を
// 「運営会社」とみなし、その会社の社員全員が運営者機能を使える
function owner_company_ids(): array {
  static $ids = null;
  if ($ids !== null) return $ids;
  $ids = [];
  if (!defined('OWNER_EMAILS')) return $ids;
  $emails = array_filter(array_map('trim', explode(',', strtolower(OWNER_EMAILS))));
  if (!$emails) return $ids;
  $ph = implode(',', array_fill(0, count($emails), '?'));
  $st = db()->prepare("SELECT DISTINCT company_id FROM users WHERE LOWER(email) IN ($ph)");
  $st->execute($emails);
  foreach ($st->fetchAll() as $r) $ids[] = (int)$r['company_id'];
  return $ids;
}
function is_owner_company(int $companyId): bool {
  return in_array($companyId, owner_company_ids(), true);
}

// 運営者のログイン確認(運営会社の社員でなければ拒否)
function require_owner(): array {
  $auth = require_auth();
  if (!is_owner_company($auth['company_id'])) respond(['error' => 'この操作は行えません'], 403);
  return $auth;
}

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
    source TEXT,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
  // 既存の表にも流入経路の列を足す(MariaDB は IF NOT EXISTS が使える)
  try { db()->exec("ALTER TABLE signups ADD COLUMN IF NOT EXISTS source TEXT"); } catch (Throwable $e) {}
  try { db()->exec("ALTER TABLE companies ADD COLUMN IF NOT EXISTS source TEXT"); } catch (Throwable $e) {}
  // 電話番号(営業やサポートの連絡先として登録時に預かる)
  try { db()->exec("ALTER TABLE signups ADD COLUMN IF NOT EXISTS phone VARCHAR(20) NOT NULL DEFAULT ''"); } catch (Throwable $e) {}
  try { db()->exec("ALTER TABLE companies ADD COLUMN IF NOT EXISTS phone VARCHAR(20) NOT NULL DEFAULT ''"); } catch (Throwable $e) {}
}

// 電話番号の体裁を整える(全角→半角、区切り記号を除き、日本の番号として桁数を確かめる)
// 保存は数字だけに統一する(表記ゆれを無くすため)。不正なら null を返す
function normalize_phone(string $raw): ?string {
  $s = mb_convert_kana(trim($raw), 'n');
  $digits = preg_replace('/[^0-9]/', '', $s);
  if (!preg_match('/^0\d{9,10}$/', $digits)) return null;
  return $digits;
}

// 使い心地アンケートの回答を保存する表(無ければ作る)
function ensure_feedback_table(): void {
  db()->exec("CREATE TABLE IF NOT EXISTS feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    user_id INT NOT NULL,
    email VARCHAR(190) NOT NULL DEFAULT '',
    rating TINYINT NOT NULL,
    good TEXT,
    request TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company (company_id)
  ) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
}

// 導入から7日たった会社に、まだ回答が無ければアンケートを出す
// (会社の誰かが1回答えれば、その会社には二度と出さない)
function feedback_due(int $companyId): bool {
  if (is_owner_company($companyId)) return false; // 運営会社には出さない
  try {
    ensure_feedback_table();
    $st = db()->prepare(
      'SELECT (SELECT COUNT(*) FROM feedback f WHERE f.company_id = c.id) AS answered,
              (c.created_at <= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS due
       FROM companies c WHERE c.id = ?'
    );
    $st->execute([$companyId]);
    $r = $st->fetch();
    return $r && (int)$r['due'] === 1 && (int)$r['answered'] === 0;
  } catch (Throwable $e) {
    return false; // アンケートの都合で本処理を止めない
  }
}

// 確認コードのメールを送る(ブランドデザインのHTML + 文字だけの控えを同封)
function send_signup_mail(string $email, string $code): bool {
  $subject = mb_encode_mimeheader('【問屋さん】メールアドレス確認コード', 'UTF-8', 'B');
  $from = '=?UTF-8?B?' . base64_encode('問屋さん') . '?= <noreply@tonyasan.jp>';

  // HTMLが表示できない環境向けの控え(文字だけ)
  $text = "「問屋さん」への新規登録ありがとうございます。\n\n"
        . "確認コード: {$code}\n\n"
        . "登録画面に、この6桁の確認コードをご入力ください。\n"
        . "コードの有効期限は20分です。\n\n"
        . "お心当たりがない場合は、このメールを破棄してください。\n\n"
        . "――――――――――――――――\n"
        . "問屋さん — 建築資材検索システム\n"
        . "https://tonyasan.jp/\n"
        . "※このメールは送信専用です。ご返信いただいてもお答えできません。";

  // 本文(主要なメールソフトで崩れないテーブル構成・インラインCSS)
  $tile = 'width:34px;height:34px;text-align:center;vertical-align:middle;'
        . 'color:#ffffff;font-family:sans-serif;font-size:19px;font-weight:bold;line-height:34px;';
  $html = '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"></head>'
    . '<body style="margin:0;padding:0;background-color:#f4f5f7;">'
    . '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f5f7;padding:32px 12px;"><tr><td align="center">'
    . '<table width="480" cellpadding="0" cellspacing="0" border="0" style="max-width:480px;width:100%;background-color:#ffffff;border-radius:14px;border:1px solid #e5e7eb;">'
    // ロゴ(4色タイル)
    . '<tr><td align="center" style="padding:36px 24px 0;">'
    . '<table cellpadding="0" cellspacing="0" border="0"><tr>'
    . '<td style="' . $tile . 'background-color:#4285F4;border-radius:8px 0 0 0;">&#21839;</td>'
    . '<td style="' . $tile . 'background-color:#EA4335;border-radius:0 8px 0 0;">&#23627;</td>'
    . '</tr><tr>'
    . '<td style="' . $tile . 'background-color:#FBBC05;border-radius:0 0 0 8px;">&#12373;</td>'
    . '<td style="' . $tile . 'background-color:#34A853;border-radius:0 0 8px 0;">&#12435;</td>'
    . '</tr></table>'
    . '<div style="margin-top:12px;font-family:sans-serif;font-size:12px;letter-spacing:4px;color:#9ca3af;">TONYASAN</div>'
    . '</td></tr>'
    // 見出し・本文
    . '<tr><td style="padding:26px 32px 0;font-family:sans-serif;">'
    . '<div style="font-size:18px;font-weight:bold;color:#111827;text-align:center;">メールアドレスの確認</div>'
    . '<p style="margin:18px 0 0;font-size:14px;line-height:1.9;color:#374151;">'
    . '「問屋さん」への新規登録ありがとうございます。<br>'
    . '下の6桁の確認コードを、登録画面にご入力ください。</p>'
    . '</td></tr>'
    // 確認コード
    . '<tr><td align="center" style="padding:22px 32px 0;">'
    . '<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
    . '<td align="center" style="background-color:#f0fdf4;border:2px solid #bbf7d0;border-radius:12px;padding:18px 10px;">'
    . '<div style="font-family:sans-serif;font-size:34px;font-weight:bold;letter-spacing:10px;color:#15803d;">' . htmlspecialchars($code) . '</div>'
    . '</td></tr></table>'
    . '<div style="margin-top:10px;font-family:sans-serif;font-size:12px;color:#6b7280;">このコードの有効期限は20分です</div>'
    . '</td></tr>'
    // 注意書き
    . '<tr><td style="padding:24px 32px 32px;font-family:sans-serif;">'
    . '<p style="margin:0;padding-top:18px;border-top:1px solid #f3f4f6;font-size:12px;line-height:1.9;color:#9ca3af;">'
    . 'お心当たりがない場合は、このメールを破棄してください。どなたかがメールアドレスを誤って入力した可能性があります。</p>'
    . '</td></tr>'
    . '</table>'
    // フッター
    . '<table width="480" cellpadding="0" cellspacing="0" border="0" style="max-width:480px;width:100%;"><tr>'
    . '<td align="center" style="padding:20px 24px;font-family:sans-serif;font-size:12px;line-height:1.9;color:#9ca3af;">'
    . '問屋さん — 建築資材検索システム<br>'
    . '<a href="https://tonyasan.jp/" style="color:#15803d;text-decoration:none;">https://tonyasan.jp/</a><br>'
    . '※このメールは送信専用です。ご返信いただいてもお答えできません。'
    . '</td></tr></table>'
    . '</td></tr></table></body></html>';

  $boundary = 'b' . bin2hex(random_bytes(12));
  $headers = "From: {$from}\r\n"
    . "Reply-To: noreply@tonyasan.jp\r\n"
    . "MIME-Version: 1.0\r\n"
    . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";
  $body = "--{$boundary}\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
    . chunk_split(base64_encode($text))
    . "--{$boundary}\r\n"
    . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
    . chunk_split(base64_encode($html))
    . "--{$boundary}--";
  return @mail($email, $subject, $body, $headers);
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
      respond(['ok' => true, 'token' => $token, 'email' => $email, 'role' => $u['role'], 'company' => $u['company_name'], 'company_id' => (int)$u['company_id'], 'owner' => is_owner_company((int)$u['company_id']), 'feedback_due' => feedback_due((int)$u['company_id'])]);
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
      respond(['ok' => true, 'email' => $auth['email'], 'role' => $auth['role'], 'company' => $c ? $c['name'] : '', 'company_id' => $auth['company_id'], 'owner' => is_owner_company($auth['company_id']), 'feedback_due' => feedback_due($auth['company_id'])]);
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

    // 同じ会社の社員一覧(管理者のみ)
    case 'list_users': {
      $auth = require_auth();
      if ($auth['role'] !== 'admin') respond(['error' => '管理者のみ操作できます'], 403);
      $st = db()->prepare('SELECT id, email, role, DATE(created_at) AS added_on FROM users WHERE company_id = ? ORDER BY created_at');
      $st->execute([$auth['company_id']]);
      respond(['ok' => true, 'users' => $st->fetchAll(), 'me' => $auth['user_id']]);
    }

    // 社員の削除(管理者のみ・自分自身は不可)
    case 'remove_user': {
      $auth = require_auth();
      if ($auth['role'] !== 'admin') respond(['error' => '管理者のみ操作できます'], 403);
      $targetId = (int)($in['user_id'] ?? 0);
      if ($targetId === $auth['user_id']) respond(['error' => '自分自身は削除できません'], 400);
      $db = db();
      $st = $db->prepare('SELECT id FROM users WHERE id = ? AND company_id = ?');
      $st->execute([$targetId, $auth['company_id']]);
      if (!$st->fetch()) respond(['error' => 'その社員が見つかりません'], 404);
      $db->prepare('DELETE FROM tokens WHERE user_id = ?')->execute([$targetId]);
      $db->prepare('DELETE FROM users WHERE id = ?')->execute([$targetId]);
      respond(['ok' => true]);
    }

    // 社員のパスワード再設定(管理者のみ・他人のみ。自分は change_password を使う)
    case 'reset_password': {
      $auth = require_auth();
      if ($auth['role'] !== 'admin') respond(['error' => '管理者のみ操作できます'], 403);
      $targetId = (int)($in['user_id'] ?? 0);
      $new = (string)($in['new'] ?? '');
      if ($targetId === $auth['user_id']) respond(['error' => 'ご自身のパスワードは「パスワード変更」から行ってください'], 400);
      if (strlen($new) < 8) respond(['error' => '新しいパスワードは8文字以上にしてください'], 400);
      $db = db();
      $st = $db->prepare('SELECT id FROM users WHERE id = ? AND company_id = ?');
      $st->execute([$targetId, $auth['company_id']]);
      if (!$st->fetch()) respond(['error' => 'その社員が見つかりません'], 404);
      $db->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')
         ->execute([password_hash($new, PASSWORD_DEFAULT), $targetId]);
      // 本人の既存ログインを無効化(新パスワードで入り直してもらう)
      $db->prepare('DELETE FROM tokens WHERE user_id = ?')->execute([$targetId]);
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
      $phone = normalize_phone((string)($in['phone'] ?? ''));
      if ($phone === null) {
        respond(['error' => '電話番号を確認してください(例: 090-1234-5678)'], 400);
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
      // 流入経路(初回アクセス時のリンク元やURLパラメータ)。無くてもよい
      $source = '';
      if (isset($in['source']) && is_array($in['source'])) {
        $src = [];
        foreach (['ref', 'utm_source', 'utm_medium', 'utm_campaign', 'at'] as $k) {
          if (!empty($in['source'][$k])) $src[$k] = mb_substr((string)$in['source'][$k], 0, 300);
        }
        if ($src) $source = json_encode($src, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      }
      $db->prepare('INSERT INTO signups (token, company, email, pass_hash, code, ip, source, phone, expires_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 20 MINUTE))')
         ->execute([$token, $company, $email, password_hash($password, PASSWORD_DEFAULT), $code, client_ip(), $source, $phone]);
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
      $db->prepare('INSERT INTO companies (name, source, phone) VALUES (?, ?, ?)')->execute([$s['company'], $s['source'] ?? null, $s['phone'] ?? '']);
      $companyId = (int)$db->lastInsertId();
      $db->prepare('INSERT INTO users (company_id, email, pass_hash, role) VALUES (?, ?, ?, "admin")')
         ->execute([$companyId, $s['email'], $s['pass_hash']]);
      $userId = (int)$db->lastInsertId();
      $db->prepare('DELETE FROM signups WHERE id = ?')->execute([(int)$s['id']]);
      $authToken = bin2hex(random_bytes(32));
      $db->prepare('INSERT INTO tokens (token, user_id, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ' . (int)TOKEN_DAYS . ' DAY))')
         ->execute([$authToken, $userId]);
      $db->commit();
      respond(['ok' => true, 'token' => $authToken, 'email' => $s['email'], 'role' => 'admin', 'company' => $s['company'], 'company_id' => $companyId, 'owner' => is_owner_company($companyId)]);
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

    // 使い心地アンケートの回答を受け取る
    case 'feedback_submit': {
      $auth = require_auth();
      ensure_feedback_table();
      $rating = (int)($in['rating'] ?? 0);
      if ($rating < 1 || $rating > 5) respond(['error' => '満足度(星1〜5)を選んでください'], 400);
      $good = mb_substr(trim((string)($in['good'] ?? '')), 0, 2000);
      $request = mb_substr(trim((string)($in['request'] ?? '')), 0, 2000);
      db()->prepare('INSERT INTO feedback (company_id, user_id, email, rating, good, request) VALUES (?, ?, ?, ?, ?, ?)')
          ->execute([$auth['company_id'], $auth['user_id'], $auth['email'], $rating, $good, $request]);
      respond(['ok' => true]);
    }

    // 運営者専用: 導入している会社の一覧(件数と利用状況のみ。中身は返さない)
    case 'owner_companies': {
      $auth = require_owner();
      db()->exec("CREATE TABLE IF NOT EXISTS api_usage (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        provider VARCHAR(20) NOT NULL,
        model VARCHAR(80) NOT NULL DEFAULT '',
        prompt_tokens INT NOT NULL DEFAULT 0,
        output_tokens INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_company_time (company_id, created_at)
      ) CHARACTER SET utf8mb4");
      db()->exec("CREATE TABLE IF NOT EXISTS activity_log (
        company_id INT NOT NULL,
        user_id INT NOT NULL,
        day DATE NOT NULL,
        actions INT NOT NULL DEFAULT 0,
        last_seen DATETIME,
        PRIMARY KEY (company_id, user_id, day)
      ) CHARACTER SET utf8mb4");
      ensure_signup_table(); // companies.source / phone 列の用意も兼ねる
      ensure_feedback_table();
      $st = db()->query(
        "SELECT c.id, c.name, DATE(c.created_at) AS since, c.source, c.phone,
          (SELECT COUNT(*) FROM feedback f WHERE f.company_id = c.id) AS fb_count,
          (SELECT AVG(f.rating) FROM feedback f WHERE f.company_id = c.id) AS fb_avg,
          (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id) AS user_count,
          (SELECT COUNT(*) FROM documents d WHERE d.company_id = c.id) AS doc_count,
          (SELECT COALESCE(SUM(d.size), 0) FROM documents d WHERE d.company_id = c.id) AS doc_bytes,
          (SELECT COALESCE(SUM(LENGTH(k.v)), 0) FROM kv_data k WHERE k.company_id = c.id) AS kv_bytes,
          (SELECT MAX(g.last_seen) FROM activity_log g WHERE g.company_id = c.id) AS last_seen,
          (SELECT COALESCE(SUM(g.actions), 0) FROM activity_log g WHERE g.company_id = c.id AND g.day >= DATE_FORMAT(NOW(), '%Y-%m-01')) AS m_actions,
          (SELECT COUNT(DISTINCT g.user_id) FROM activity_log g WHERE g.company_id = c.id AND g.day >= DATE_FORMAT(NOW(), '%Y-%m-01')) AS m_active_users,
          (SELECT COUNT(DISTINCT g.day) FROM activity_log g WHERE g.company_id = c.id AND g.day >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)) AS d7_days,
          (SELECT COUNT(*) FROM api_usage a WHERE a.company_id = c.id AND a.created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')) AS m_calls,
          (SELECT COALESCE(SUM(a.prompt_tokens), 0) FROM api_usage a WHERE a.company_id = c.id AND a.created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')) AS m_in,
          (SELECT COALESCE(SUM(a.output_tokens), 0) FROM api_usage a WHERE a.company_id = c.id AND a.created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')) AS m_out,
          (SELECT COALESCE(SUM(a.prompt_tokens), 0) FROM api_usage a WHERE a.company_id = c.id) AS t_in,
          (SELECT COALESCE(SUM(a.output_tokens), 0) FROM api_usage a WHERE a.company_id = c.id) AS t_out
         FROM companies c ORDER BY c.created_at"
      );
      $companies = $st->fetchAll();
      // モデルごとの単価(USD/100万トークン)で正確に集計する
      $priceOf = function (string $model): array {
        if (strpos($model, 'flash-lite') !== false) return [0.30, 2.50]; // Gemini 3.5 Flash-Lite
        return [1.50, 9.00]; // Gemini 3.5 Flash ほか
      };
      $monthStart = date('Y-m-01');
      $costs = []; // company_id => [m_cost, t_cost]
      $uq = db()->prepare(
        "SELECT company_id, model,
           SUM(CASE WHEN created_at >= ? THEN prompt_tokens ELSE 0 END) AS m_in,
           SUM(CASE WHEN created_at >= ? THEN output_tokens ELSE 0 END) AS m_out,
           SUM(prompt_tokens) AS t_in, SUM(output_tokens) AS t_out
         FROM api_usage GROUP BY company_id, model"
      );
      $uq->execute([$monthStart, $monthStart]);
      foreach ($uq->fetchAll() as $r) {
        [$pi, $po] = $priceOf((string)$r['model']);
        $cid = (int)$r['company_id'];
        if (!isset($costs[$cid])) $costs[$cid] = [0.0, 0.0];
        $costs[$cid][0] += ((int)$r['m_in'] / 1e6) * $pi + ((int)$r['m_out'] / 1e6) * $po;
        $costs[$cid][1] += ((int)$r['t_in'] / 1e6) * $pi + ((int)$r['t_out'] / 1e6) * $po;
      }
      foreach ($companies as &$c) {
        $cid = (int)$c['id'];
        $c['m_cost_usd'] = round($costs[$cid][0] ?? 0.0, 4);
        $c['t_cost_usd'] = round($costs[$cid][1] ?? 0.0, 4);
      }
      unset($c);
      $rates = [
        'in_usd' => 1.50, 'out_usd' => 9.00, // 参考表示用(通常モデルの単価)
        'lite_in_usd' => 0.30, 'lite_out_usd' => 2.50,
        'usd_jpy' => defined('USD_JPY') ? (float)USD_JPY : 150.0,
      ];
      // アンケートの回答本文(新しい順。導入社が増えても重くならないよう500件まで)
      $fq = db()->query(
        "SELECT company_id, email, rating, good, request, DATE(created_at) AS on_day
         FROM feedback ORDER BY id DESC LIMIT 500"
      );
      respond(['ok' => true, 'companies' => $companies, 'my_company' => $auth['company_id'], 'rates' => $rates, 'feedback' => $fq->fetchAll()]);
    }

    // 運営者専用: 会社をデータごと削除(自社は不可)
    case 'owner_remove_company': {
      $auth = require_owner();
      $cid = (int)($in['company_id'] ?? 0);
      if ($cid === $auth['company_id']) respond(['error' => '自社は削除できません'], 400);
      $db = db();
      $st = $db->prepare('SELECT name FROM companies WHERE id = ?');
      $st->execute([$cid]);
      $c = $st->fetch();
      if (!$c) respond(['error' => 'その会社が見つかりません'], 404);
      // 書類ファイルの実体を先に削除
      $st = $db->prepare('SELECT path FROM documents WHERE company_id = ?');
      $st->execute([$cid]);
      foreach ($st->fetchAll() as $r) {
        $full = rtrim(FILES_DIR, '/') . '/' . $r['path'];
        $real = realpath($full);
        $base = realpath(FILES_DIR);
        if ($real !== false && $base !== false && strpos($real, $base) === 0) @unlink($real);
      }
      @rmdir(rtrim(FILES_DIR, '/') . '/' . $cid);
      $db->prepare('DELETE FROM tokens WHERE user_id IN (SELECT id FROM users WHERE company_id = ?)')->execute([$cid]);
      $db->prepare('DELETE FROM documents WHERE company_id = ?')->execute([$cid]);
      $db->prepare('DELETE FROM kv_data WHERE company_id = ?')->execute([$cid]);
      // 利用記録・アンケートも残さない(表がまだ無い環境でも止まらないようにする)
      foreach (['feedback', 'activity_log', 'api_usage'] as $tbl) {
        try { $db->prepare("DELETE FROM {$tbl} WHERE company_id = ?")->execute([$cid]); } catch (Throwable $e) {}
      }
      $db->prepare('DELETE FROM users WHERE company_id = ?')->execute([$cid]);
      $db->prepare('DELETE FROM companies WHERE id = ?')->execute([$cid]);
      respond(['ok' => true, 'removed' => $c['name']]);
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
