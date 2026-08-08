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

## 公開先(2026-08-09 に公開済み)

| 項目 | 値 |
| --- | --- |
| リポジトリ | `yamamoto-coder/zairyou` |
| 公開 URL | <https://yamamoto-coder.github.io/zairyou/> |
| ブランチ | `main`(`/(root)` を GitHub Pages が配信) |

このマシンには `gh` CLI が入っていません。
リポジトリと Pages の設定はユーザーが GitHub の Web UI で済ませてあるため、Claude Code 側は素の `git` だけで完結します。
push は Git Credential Manager に保存済みの資格情報で通ります。

## 想定タスク

### 更新(`index.html` を修正した後)

1. 修正内容ごとにコミットを分ける(`git-commit` スキルに従う)。
2. `git push` する。
3. Pages は push から1〜2分で反映されます。ユーザーには Ctrl+F5 での強制再読み込みを案内してください(CDN キャッシュ対策)。

### 初期設定の案内

アプリの「データ管理 → 読み取りエンジン → Gemini API キー入力 → 保存 → 接続テスト」の順に案内します。

## 厳守事項

- **APIキー・トークンを絶対にコミットしない**(コード内埋め込みも禁止)。
  キー入力はアプリの設定画面経由のみ。
- `index.html` の `<script type="text/babel">` 内のコードに
  `</script>` という文字列を含めない(スクリプトが分断され白画面になる)。
- JSXは classic runtime 前提(先頭の Babel.registerPreset を変更しない)。
- 動作確認は必ずブラウザ実表示で行う。白画面時は DevTools Console を確認。
