# 問屋さん iOS アプリ — App Store 提出手順書

Web 版(<https://tonyasan.jp/>)を **Capacitor の薄い殻**(`native/`)で包み、
App Store に「問屋さん」として出すための手順。
画面本体は今まで通り Web 側(`index.html`)なので、審査を通ったあとの機能追加は
**Web を更新するだけで届く**(ネイティブ側の変更があるときだけ再提出)。

| 項目 | 値 |
| --- | --- |
| Bundle ID | `jp.tonyasan.app` |
| 表示名 | 問屋さん |
| ストア名(案) | 問屋さん — 建築資材の価格検索 |
| 読み込み先 | `https://tonyasan.jp/`(`native/capacitor.config.ts` の `server.url`) |
| Xcode プロジェクト | `native/ios/App/App.xcodeproj`(SPM。CocoaPods 不要) |
| プライバシーポリシー | <https://tonyasan.jp/privacy.html> |
| サポート URL | <https://kyoei-sakai.com/contact/> |
| 価格 | 無料(アプリ内課金なし) |

関連ファイル: [metadata.md](./metadata.md)(ストア掲載文)/ [review-notes.md](./review-notes.md)(審査員向けメモ)/ [privacy-labels.md](./privacy-labels.md)(App プライバシーの回答)/ [checklist.md](./checklist.md)(提出直前チェック)

---

## 0. 全体の流れ

```
① Web 側の変更を本番に反映(GitHub Pages + tonyasan.jp/Xserver)
② Xcode でチーム(署名)を選び、実機で1回動かす
③ App Store Connect にアプリを作成し、掲載文・スクリーンショット・プライバシー回答を入れる
④ Xcode で Archive → App Store Connect へアップロード
⑤ (任意)TestFlight で社内テスト
⑥ 審査に提出 → 承認 → リリース
```

所要の目安: ①〜④ で半日、審査は通常 1〜3 日。

---

## 1. Web 側の変更を本番に反映する(必須・先にやる)

今回の提出に合わせて Web 側に **審査で必須のもの**を入れた。アプリは本番の
`tonyasan.jp` を読み込むので、**アップロードしないとアプリにも反映されない**。

| 変更 | 理由 |
| --- | --- |
| `index.html` — 設定 → アカウントに「アカウントの削除」を追加 | App Store 審査ガイドライン 5.1.1(v)。アプリ内で登録できるなら削除もできる必要がある |
| `server/api/auth.php` — `delete_account` の処理を追加 | 上の削除ボタンの受け口 |
| `privacy.html`(新規)+ ログイン/登録/設定/LP からのリンク | プライバシーポリシーの公開 URL は提出時の必須項目 |
| `index.html` — iOS アプリから開いたときだけ共有シート・ファイル保存を使う | WKWebView ではブラウザの「ダウンロード」が動かないため |

### 1-1. GitHub Pages(自動)

```bash
cd ~/Desktop/開発アプリ/zairyou && git push origin main
```

1〜2 分で <https://yamamoto-coder.github.io/zairyou/> に反映。

### 1-2. tonyasan.jp(Xserver・手動アップロード)

Xserver のファイルマネージャ(または FTP)で、`tonyasan.jp` の公開フォルダに以下を上書き:

| ローカル | アップロード先(公開フォルダからの相対) |
| --- | --- |
| `index.html` | `index.html` |
| `privacy.html` | `privacy.html` |
| `sitemap.xml` | `sitemap.xml` |
| `lp/index.html` | `lp/index.html` |
| `server/api/auth.php` | `api/auth.php` |

確認:

- <https://tonyasan.jp/privacy.html> が開く
- <https://tonyasan.jp/> のログイン画面の下に「プライバシーポリシー」リンクが出る
- ログイン → データ管理 → アカウント の一番下に「アカウントの削除」が出る
- 動作確認用の会社を1つ作って実際に削除できる(社員が自分だけなら会社ごと消える)

---

## 2. Mac 側の準備(初回のみ)

- Xcode 26 が入っている(`xcodebuild -version`)。もし iOS シミュレータ連携ツールが
  「Xcode is installed but not selected」と言う場合は
  `sudo xcode-select -s /Applications/Xcode.app/Contents/Developer` を一度実行する。
- Node は `~/.local/node/bin` にある。ターミナルで `node -v` が通れば OK。
- 依存の導入(`native/node_modules` はコミットしていない):

```bash
cd ~/Desktop/開発アプリ/zairyou/native && npm ci
```

- Apple Developer Program(法人)に加入済みで、Xcode → Settings → Accounts に
  その Apple ID を追加しておく。**Account Holder か Admin** の権限が要る
  (App Store Connect でアプリを作れるのはこの権限)。

---

## 3. Xcode で署名を通して実機で動かす

```bash
cd ~/Desktop/開発アプリ/zairyou/native && npx cap sync ios && npx cap open ios
```

Xcode が開いたら:

1. 左のナビゲータで **App** プロジェクト → TARGETS **App** → **Signing & Capabilities**
2. **Automatically manage signing** にチェック、**Team** に法人チームを選ぶ
   (Bundle Identifier `jp.tonyasan.app` が自動で登録される。もし他で使われていると
   エラーになるので、その場合は `native/capacitor.config.ts` の `appId` と Xcode の
   Bundle Identifier を例えば `jp.tonyasan.ios` に揃えて `npx cap sync ios`)
3. 上部のデバイス選択で **自分の iPhone**(USB 接続 or 同一 Wi-Fi)を選び ▶ Run
   - 初回は iPhone 側で 設定 → 一般 → VPNとデバイス管理 で開発者を信頼
4. 実機で確認すること
   - 起動画面(4色ロゴ)→ ログイン画面が出る
   - ログイン → ホームで「取り込む」→ **写真を撮る / 写真ライブラリ / ファイル**が出る(カメラ許可ダイアログの文言が日本語)
   - 発注 → 「共有…」で共有シートが開く
   - データ管理 → バックアップ「書き出す」で共有シート(「ファイルに保存」)が出る
   - 機内モードで起動 → 「インターネットに接続できません」の画面が出て、復帰で戻る

シミュレータだけで確認したいとき(署名不要):

```bash
cd ~/Desktop/開発アプリ/zairyou/native/ios/App && xcodebuild -project App.xcodeproj -scheme App -configuration Debug -sdk iphonesimulator -destination 'platform=iOS Simulator,name=iPhone 17 Pro Max' CODE_SIGNING_ALLOWED=NO -quiet build
```

---

## 4. App Store Connect でアプリを作る

<https://appstoreconnect.apple.com/> → マイApp → **+** → 新規App

| 欄 | 入力 |
| --- | --- |
| プラットフォーム | iOS |
| 名前 | `問屋さん — 建築資材の価格検索`(30文字以内。重複で弾かれたら [metadata.md](./metadata.md) の代案) |
| プライマリ言語 | 日本語 |
| バンドルID | `jp.tonyasan.app`(手順3で Xcode が登録したものを選ぶ) |
| SKU | `tonyasan-ios-001` |
| ユーザアクセス | フルアクセス |

作成後、左メニューで順に埋める:

### 4-1. App情報

- サブタイトル・カテゴリ(**ビジネス** / 補助: 仕事効率化)・コンテンツ権利(第三者コンテンツなし)
- **年齢制限指定** → 質問にすべて「なし」→ 4+
- **プライバシーポリシー URL**: `https://tonyasan.jp/privacy.html`

### 4-2. 価格および配信状況

- 価格: 無料 / 配信国: 日本(必要なら すべての国)

### 4-3. Appのプライバシー

[privacy-labels.md](./privacy-labels.md) の表のとおりに回答(「データを収集する」→ 種類ごとに用途・紐付け・トラッキング)。

### 4-4. 1.0 提出の準備(バージョン情報)

- **スクリーンショット**(必須): iPhone 6.9インチ(1320×2868)と、iPad 13インチ(2064×2752)。
  撮り方は §6。3〜6 枚。**ログイン画面だけでは不可**(実際の機能画面を含める)
- プロモーション用テキスト / 概要 / キーワード / サポート URL / マーケティング URL → [metadata.md](./metadata.md)
- **App Review に関する情報**
  - サインインが必要: **オン** → デモ用のメール/パスワードを記入([review-notes.md](./review-notes.md) §1 の手順で作る)
  - メモ欄: [review-notes.md](./review-notes.md) §2 の英文をそのまま貼る
  - 連絡先: 担当者名・電話・メール
- **バージョンのリリース**: 「審査承認後に手動でリリース」を推奨(公開日を自分で決められる)
- 輸出コンプライアンス: Info.plist に `ITSAppUsesNonExemptEncryption=false` を入れてあるので、アップロード時の質問は出ない

---

## 5. Archive してアップロード

1. Xcode でデバイス選択を **Any iOS Device (arm64)** にする
2. メニュー **Product → Archive**(数分)
3. Organizer が開く → **Distribute App** → **App Store Connect** → **Upload** → 既定のまま Next → Upload
4. 10〜30 分で App Store Connect の「ビルド」に現れる(処理中はメールが来る)
5. 4-4 の画面で **ビルドを追加** → そのビルドを選ぶ
6. すべての欄が埋まると「審査へ提出」が押せる

バージョンを上げて再提出するとき: Xcode の General → **Version**(1.0.1 など)と
**Build**(2, 3, …)を上げてから Archive。同じ Build 番号は2度アップロードできない。

---

## 6. スクリーンショットの撮り方(シミュレータ)

App Store Connect が受け付けるサイズちょうどで撮れる。ログイン後の実画面を撮ること。

```bash
# 端末を起動してアプリを入れる(初回)
cd ~/Desktop/開発アプリ/zairyou/native && ./scripts/sim.sh boot "iPhone 17 Pro Max"
# Simulator アプリの画面でログインし、撮りたい画面を出したら:
./scripts/sim.sh shot iphone-01-home
./scripts/sim.sh shot iphone-02-search
# iPad も同じ流れ
./scripts/sim.sh boot "iPad Pro 13-inch (M5)"
./scripts/sim.sh shot ipad-01-home
```

保存先: `docs/appstore/screenshots/`(コミットしない。`.gitignore` 済み)。
撮る画面の候補: ホーム(取り込み)/ 検索結果 / 明細の価格履歴 / 発注カート / データ管理。
※ シミュレータではカメラが使えないので、取り込みは「ファイル」から PDF を選ぶか、実機で撮る。

---

## 7. TestFlight(任意・推奨)

アップロード済みビルドは、審査なしで **内部テスター**(App Store Connect のユーザー、最大100人)に配れる。
TestFlight → 内部テスト → グループを作りメンバー追加 → ビルドを追加。社内で数日使ってから審査に出すと安心。

---

## 8. 審査で指摘されやすい点と用意した対策

| ガイドライン | 内容 | 対策(済) |
| --- | --- | --- |
| 2.1 App の完全性 | ログインが必要なアプリはデモアカウント必須 | review-notes.md の手順で発行 |
| 4.2 最低限の機能 | 「Web サイトをそのまま包んだだけ」は却下 | 実務ツール(AI 読み取り・カメラ取り込み・共有シート・オフライン画面)。review-notes に説明文あり |
| 5.1.1(v) アカウント削除 | 登録できるならアプリ内で削除できること | 設定 → アカウント → アカウントの削除 |
| 5.1.1(i) プライバシー | ポリシー URL と、カメラ・写真の用途文言 | privacy.html / Info.plist |
| 2.3.3 スクリーンショット | 実際の画面であること | §6 で撮る |
| 3.1.1 課金 | 外部決済への誘導禁止 | 完全無料。LP の料金表示も 0 円のみ |
| 4.8 Sign in with Apple | 他社ソーシャルログインがある場合のみ必須 | メール+パスワードのみなので不要 |

却下されたら Resolution Center に理由が来る。多くは説明の追記か小修正で再提出できる。

---

## 9. 承認後の運用

- **Web 側の更新**: 今まで通り `git push`(GitHub Pages)+ tonyasan.jp へアップロード。アプリは次回起動時に新しい画面を読み込む。再提出不要
- **ネイティブ側の更新**(アイコン、権限文言、プラグイン追加、`capacitor.config.ts`): Version/Build を上げて §5 を再実行
- 年1回、Apple Developer Program の更新(自動更新にしておく)
- 招待文(社員を追加したときの案内)に App Store のリンクを足すと便利
  (承認後に `https://apps.apple.com/jp/app/idXXXXXXXXX` が決まる)
