-- 問屋さん サーバー保存用データベース
-- Xserver の phpMyAdmin でこのファイルの内容を実行してください。
-- (対象のデータベースを選んでから「SQL」タブに貼り付けて実行)

CREATE TABLE IF NOT EXISTS companies (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT UNSIGNED NOT NULL,
  email VARCHAR(190) NOT NULL,
  pass_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','member') NOT NULL DEFAULT 'member',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_email (email),
  KEY idx_company (company_id),
  CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tokens (
  token CHAR(64) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (token),
  KEY idx_user (user_id),
  CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- アプリのデータ本体(会社ごとのキー・バリュー保存。
-- フロントの window.storage と同じ形にしてあるため、全機能がこの1表で動く)
CREATE TABLE IF NOT EXISTS kv_data (
  company_id INT UNSIGNED NOT NULL,
  k VARCHAR(100) NOT NULL,
  v MEDIUMTEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by INT UNSIGNED NULL,
  PRIMARY KEY (company_id, k),
  CONSTRAINT fk_kv_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 取り込んだ書類の原本(PDF/写真)の台帳。実ファイルは files/ 配下に保存
CREATE TABLE IF NOT EXISTS documents (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT UNSIGNED NOT NULL,
  doc_id VARCHAR(190) NOT NULL,
  invoice_id VARCHAR(190) NOT NULL,
  name VARCHAR(255) NOT NULL,
  mime VARCHAR(100) NOT NULL DEFAULT '',
  hash CHAR(64) NOT NULL DEFAULT '',
  size INT UNSIGNED NOT NULL DEFAULT 0,
  path VARCHAR(255) NOT NULL,
  added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_company_doc (company_id, doc_id),
  KEY idx_company_invoice (company_id, invoice_id),
  KEY idx_company_hash (company_id, hash),
  CONSTRAINT fk_docs_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ログイン試行の記録(総当たり攻撃の抑止に使用)
CREATE TABLE IF NOT EXISTS login_attempts (
  ip VARCHAR(45) NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
