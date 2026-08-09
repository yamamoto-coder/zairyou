# 問屋さん サーバー(Xserver)設置手順

アプリのデータを各パソコンではなくサーバー(Xserver)に保存し、
会社ごとに分離されたデータとログインで運用するための仕組みです。
AI の APIキーもサーバーの中だけに置き、利用者のブラウザには渡しません。

## 構成

```
GitHub Pages(アプリ本体・今まで通り)
   │  HTTPS
   ▼
Xserver
 └─ public_html/tonya-api/
     ├─ api/            … 窓口プログラム(PHP)
     │   ├─ config.php  … 秘密の設定(DBパスワード・AIキー)★手で作る
     │   ├─ auth.php    … ログイン・会社/ユーザー管理
     │   ├─ storage.php … アプリデータの保存(会社ごと)
     │   ├─ docs.php    … 書類原本(PDF/写真)の保存・閲覧
     │   └─ ai.php      … AI中継(キーはサーバー内のみ)
     └─ files/          … 原本ファイル置き場(直接閲覧は禁止設定済み)
MySQL(Xserver)… 明細・発注履歴などのデータ本体
```

## 設置手順(サーバーパネルでの作業)

### 1. データベースを作る

1. Xserver サーバーパネル →「MySQL設定」
2. 「MySQL追加」でデータベースを作成(例: `tonya`)
3. 「MySQLユーザ追加」でユーザーを作成し、上のDBに**全権限で追加**
4. 表示される「MySQLホスト名」「DB名」「ユーザー名」「パスワード」を控える

### 2. テーブルを作る

1. サーバーパネル →「phpmyadmin」にログイン
2. 左で作成したDBを選択 →「SQL」タブ
3. このフォルダの `schema.sql` の中身を貼り付けて「実行」

### 3. ファイルを置く

1. サーバーパネル →「ファイルマネージャ」(または FTP)
2. `<ドメイン>/public_html/` の下に `tonya-api` フォルダを作成
3. この `server` フォルダの中身(`api` と `files`)をそのままアップロード
   - `.htaccess` も必ず一緒に(表示されない場合は隠しファイル表示をON)

### 4. config.php を作る

1. `api/config.sample.php` を複製して `api/config.php` を作成
2. 中身を実際の値に書き換える:
   - DB_HOST / DB_NAME / DB_USER / DB_PASS … 手順1で控えた値
   - GEMINI_API_KEY / ANTHROPIC_API_KEY … 会社で用意したAIキー
   - SETUP_TOKEN … 長いランダム文字列(会社の新規登録に使う合言葉)
3. **config.php は絶対に GitHub に上げない**(このリポジトリでは除外設定済み)

### 5. 動作確認

ブラウザで `https://<ドメイン>/tonya-api/api/auth.php` を開き、
`{"error":"不明な操作です"}` と表示されれば設置成功です
(config.php が無いと「サーバー未設定です」と出ます)。

### 6. 最初の会社と管理者を登録

アプリに組み込む初期設定画面から行うか、以下でも登録できます
(ターミナルの例。SETUP_TOKEN は config.php の値):

```bash
curl -X POST https://<ドメイン>/tonya-api/api/auth.php -H "Content-Type: application/json" -d '{"action":"setup","setup_token":"<SETUP_TOKEN>","company":"株式会社◯◯","email":"admin@example.com","password":"8文字以上のパスワード"}'
```

## セキュリティ設計(漏洩対策)

- AIキー・DBパスワードは `config.php` のみに存在し、`.htaccess` で
  Web からの閲覧を遮断。GitHub にも含めない
- 全データ操作は「ログイン者の会社ID」での絞り込みを必ず通す
  (他社データには構造上届かない)
- パスワードは不可逆ハッシュ(bcrypt)で保存。平文では持たない
- ログインは64桁のランダムなトークン(有効期限つき)で管理
- SQL は全てプリペアドステートメント(注入攻撃対策)
- 原本ファイルは推測不能なファイル名+直接閲覧禁止。閲覧は必ず
  ログイン確認を通る `docs.php` 経由
- 許可した公開元(GitHub Pages)以外のサイトからのブラウザ呼び出しを拒否
- ログイン総当たりは同一IPで10分10回まで
- HTTPS 以外のアクセスを拒否

## 運用メモ

- バックアップ: Xserver の自動バックアップ(14日)に加え、
  phpMyAdmin の「エクスポート」で任意時点のDBを保存できます
- 会社の追加: SETUP_TOKEN を知る人だけが登録できます
- ユーザーの追加: 各会社の管理者がアプリ内(またはAPI)から追加します
