<?php
// ============================================================
// 問屋さん API 設定ファイルの見本
//
// このファイルを「config.php」という名前でコピーして、
// 実際の値を入れてください。config.php は絶対に GitHub に
// 上げないこと(.gitignore 済み)。APIキー・パスワードは
// このサーバーの中だけに置きます。
// ============================================================

// ---- データベース(Xserver のサーバーパネル「MySQL設定」の値) ----
define('DB_HOST', 'mysqlXXXX.xserver.jp'); // MySQLホスト名
define('DB_NAME', 'xxxxx_tonya');          // データベース名
define('DB_USER', 'xxxxx_tonya');          // ユーザー名
define('DB_PASS', 'ここにDBパスワード');

// ---- AI の APIキー(サーバーの裏側にだけ置く。利用者には見えない) ----
define('GEMINI_API_KEY', '');    // Google AI Studio で発行(空なら Gemini 不使用)
define('ANTHROPIC_API_KEY', ''); // console.anthropic.com で発行(空なら Claude 不使用)
define('GEMINI_MODEL', 'gemini-3.5-flash');

// ---- アプリの公開元(これ以外のサイトからの呼び出しを拒否) ----
define('ALLOWED_ORIGIN', 'https://yamamoto-coder.github.io');

// ---- 初期設定用の合言葉(会社の新規登録に必要。長くランダムな文字列に) ----
define('SETUP_TOKEN', 'ここに推測されない長い文字列');

// ---- ログインの有効期限(日) ----
define('TOKEN_DAYS', 30);

// ---- 原本ファイルの保存先(api の外・公開されないよう .htaccess で保護) ----
define('FILES_DIR', __DIR__ . '/../files');

// ---- アップロード上限(バイト) ----
define('MAX_UPLOAD_BYTES', 40 * 1024 * 1024);
