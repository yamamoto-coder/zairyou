<?php
// 共通処理: DB接続・CORS・認証・応答
// セキュリティ方針:
//  - SQL は全てプリペアドステートメント(SQLインジェクション対策)
//  - 全データ操作は「ログイン者の会社IDでの絞り込み」を必ず通す
//  - 応答は JSON のみ。エラー詳細(SQL等)は利用者に返さない

declare(strict_types=1);

if (!file_exists(__DIR__ . '/config.php')) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error' => 'サーバー未設定です(config.php がありません)']);
  exit;
}
require __DIR__ . '/config.php';

// ---- CORS(許可した公開元だけがブラウザから呼べる) ----
function send_cors_headers(): void {
  $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
  if ($origin === ALLOWED_ORIGIN) {
    header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Max-Age: 86400');
  }
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
  }
}
send_cors_headers();

// ---- HTTPS 強制 ----
if ((($_SERVER['HTTPS'] ?? '') !== 'on') && (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== 'https')) {
  respond(['error' => 'HTTPS でアクセスしてください'], 400);
}

function db(): PDO {
  static $pdo = null;
  if ($pdo === null) {
    $pdo = new PDO(
      'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
      DB_USER,
      DB_PASS,
      [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
      ]
    );
  }
  return $pdo;
}

function respond($data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('X-Content-Type-Options: nosniff');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function json_input(): array {
  $raw = file_get_contents('php://input');
  if ($raw === '' || $raw === false) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function bearer_token(): string {
  // サーバーによって Authorization ヘッダーの渡り方が異なるため順に探す
  $h = $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? $_SERVER['PHP_AUTH_DIGEST']
    ?? '';
  if ($h === '' && function_exists('apache_request_headers')) {
    foreach (apache_request_headers() as $k => $v) {
      if (strtolower($k) === 'authorization') { $h = $v; break; }
    }
  }
  if (preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $h, $m)) return strtolower($m[1]);
  // 最後の手段: クエリ文字列で受け取る(ヘッダーが通らない環境向け)
  $q = $_GET['_t'] ?? '';
  if (is_string($q) && preg_match('/^[a-f0-9]{64}$/i', $q)) return strtolower($q);
  return '';
}

// ログイン確認。成功時は ['user_id','company_id','email','role'] を返す
function require_auth(): array {
  $token = bearer_token();
  if ($token === '') respond(['error' => 'ログインが必要です', 'code' => 'auth'], 401);
  $st = db()->prepare(
    'SELECT t.token, t.expires_at, u.id AS user_id, u.company_id, u.email, u.role
     FROM tokens t JOIN users u ON u.id = t.user_id
     WHERE t.token = ? AND t.expires_at > NOW()'
  );
  $st->execute([$token]);
  $row = $st->fetch();
  if (!$row) respond(['error' => 'ログインの有効期限が切れました', 'code' => 'auth'], 401);
  // 有効期限を延長(使い続けている限りログインが切れない)
  $up = db()->prepare('UPDATE tokens SET expires_at = DATE_ADD(NOW(), INTERVAL ' . (int)TOKEN_DAYS . ' DAY) WHERE token = ?');
  $up->execute([$token]);
  return [
    'user_id' => (int)$row['user_id'],
    'company_id' => (int)$row['company_id'],
    'email' => $row['email'],
    'role' => $row['role'],
  ];
}

function client_ip(): string {
  return substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
}

// ログイン総当たり対策: 直近10分で10回失敗した IP は拒否
function check_login_rate(): void {
  $st = db()->prepare('SELECT COUNT(*) AS c FROM login_attempts WHERE ip = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)');
  $st->execute([client_ip()]);
  if ((int)$st->fetch()['c'] >= 10) {
    respond(['error' => '試行回数が多すぎます。しばらく待ってからお試しください'], 429);
  }
}

function record_login_failure(): void {
  $st = db()->prepare('INSERT INTO login_attempts (ip) VALUES (?)');
  $st->execute([client_ip()]);
  db()->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
}
