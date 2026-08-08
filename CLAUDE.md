# 問屋さん — プロジェクト指示書(Claude Code 用)

## このプロジェクトについて

建築資材(塗料・木材・壁紙・金物など)の請求書PDF/写真をAIで読み取り、
価格と購入履歴を検索できる社内Webアプリ。
**`index.html` 1ファイルで完結**しており、GitHub Pages で静的配信する。
ビルド工程・npm install・サーバーは不要。

- `index.html` — アプリ本体(React + Babel standalone をCDN読み込み)
- `README.md` — 利用者向け説明
- 読み取りAIは Google Gemini(利用者が画面からAPIキーを設定。キーは
  各ブラウザの localStorage に保存され、リポジトリには含まれない)

## 想定タスク

### 初回公開(「GitHubにアップロードして公開して」と言われたら)

1. `gh auth status` で GitHub CLI のログインを確認。未ログインなら
   `gh auth login` をユーザーに実行してもらう(認証操作は本人に委ねる)
2. このディレクトリで git 初期化〜push:
   ```
   git init -b main
   git add index.html README.md CLAUDE.md .gitignore
   git commit -m "feat: 問屋さん 初回公開"
   gh repo create tonya --public --source=. --push
   ```
   ※リポジトリ名の希望があればそれに従う。迷ったら public / `tonya`。
3. GitHub Pages を有効化:
   ```
   gh api repos/{owner}/tonya/pages -X POST \
     -f "source[branch]=main" -f "source[path]=/" 
   ```
   409(既に有効)は成功扱いでよい。
4. 公開URL(`https://<owner>.github.io/tonya/`)を
   `gh api repos/{owner}/tonya/pages --jq .html_url` で取得して報告する。
5. 最後にユーザーへ初期設定を案内: アプリの
   「データ管理 → 読み取りエンジン → Gemini APIキー入力 → 保存 → 接続テスト」。

### 更新(index.html を修正した後)

```
git add -A && git commit -m "<変更内容>" && git push
```
Pages は push から1〜2分で反映。ユーザーには Ctrl+F5 での
強制再読み込みを案内する(CDNキャッシュ対策)。

## 厳守事項

- **APIキー・トークンを絶対にコミットしない**(コード内埋め込みも禁止)。
  キー入力はアプリの設定画面経由のみ。
- `index.html` の `<script type="text/babel">` 内のコードに
  `</script>` という文字列を含めない(スクリプトが分断され白画面になる)。
- JSXは classic runtime 前提(先頭の Babel.registerPreset を変更しない)。
- 動作確認は必ずブラウザ実表示で行う。白画面時は DevTools Console を確認。
