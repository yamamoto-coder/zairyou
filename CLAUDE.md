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

リポジトリと Pages の設定はユーザーが GitHub の Web UI で済ませてあるため、Claude Code 側は素の `git` だけで完結します
(Mac には `gh` CLI も入っています)。push は保存済みの資格情報で通ります。
Mac 上の作業コピーは `~/Desktop/開発アプリ/zairyou`。

## 自社ドメインと iOS アプリ(2026-08-17 追加)

| 項目 | 値 |
| --- | --- |
| 本番 URL | <https://tonyasan.jp/>(Xserver。同じ `index.html` を手動アップロード。API は `api/` 配下) |
| プライバシーポリシー / 利用規約 | `privacy.html` / `terms.html` → <https://tonyasan.jp/privacy.html> / <https://tonyasan.jp/terms.html> |
| iOS アプリ | `native/`(Capacitor 8・SPM)。WKWebView が `https://tonyasan.jp/` を読み込む薄い殻。Bundle ID `jp.tonyasan.app` |
| 提出手順 | `docs/appstore/README.md`(掲載文・審査メモ・プライバシー回答も同フォルダ) |

- `index.html` は `window.Capacitor` を検出したときだけ共有シート等を使う(`NATIVE_APP`)。ブラウザの動きは変えない。
- ネイティブ側(`native/capacitor.config.ts`・`Info.plist`・アイコン)を変えたら Version/Build を上げて再提出。Web 側だけの変更は再提出不要。
- `native/ios/App/App/Info.plist` のカメラ・写真の用途文言と `ITSAppUsesNonExemptEncryption=false` を消さない。
- 署名材料(証明書・プロビジョニング・.p12)は絶対にコミットしない(`.gitignore` 済み)。

## 想定タスク

### 更新(`index.html` を修正した後)

1. 修正内容ごとにコミットを分ける(`git-commit` スキルに従う)。
2. `git push` する。
3. Pages は push から1〜2分で反映されます。ユーザーには Ctrl+F5 での強制再読み込みを案内してください(CDN キャッシュ対策)。

### 初期設定(管理者のみ)

「読み取りエンジン」(API キー設定・接続テスト)は管理者モード限定です。
URL の末尾に `#admin` を付けて開くと、「データ管理」内に設定欄が現れます
(例: `https://yamamoto-coder.github.io/zairyou/#admin`)。
通常の URL では設定欄は表示されず、キー未設定の場合は
「管理者にご連絡ください」の案内だけが出ます。
キーは各パソコンのブラウザ(localStorage)に保存されるため、
新しいパソコンごとに管理者が `#admin` で開いて設定します。

注意: 静的サイトのため、この仕組みは「一般ユーザーに見せない」ための
ものであり、技術者が DevTools を使えばキーを取り出せます。
本気の秘匿が必要になったらサーバー側プロキシへの移行が必要です。

## 厳守事項

- **APIキー・トークンを絶対にコミットしない**(コード内埋め込みも禁止)。
  キー入力はアプリの設定画面経由のみ。
- `index.html` の `<script type="text/babel">` 内のコードに
  `</script>` という文字列を含めない(スクリプトが分断され白画面になる)。
- JSXは classic runtime 前提(先頭の Babel.registerPreset を変更しない)。
- 動作確認は必ずブラウザ実表示で行う。白画面時は DevTools Console を確認。
