# 提出直前チェックリスト

「今から審査に出す」ときに上から順に確認する。詳細は [README.md](./README.md)。

## Web 側(本番反映)

- [ ] `git push origin main` 済み(GitHub Pages が最新)
- [ ] tonyasan.jp に `index.html` / `privacy.html` / `terms.html` / `sitemap.xml` / `lp/index.html` / `api/auth.php` をアップロード済み
- [ ] <https://tonyasan.jp/privacy.html> と <https://tonyasan.jp/terms.html> が開く
- [ ] ログイン画面下に「利用規約・プライバシーポリシー」、登録画面に同意文が見える
- [ ] 設定 → アカウント →「アカウントの削除」が動く(テスト会社で実施)
- [ ] 実機アプリで 取り込み(カメラ)/ 検索 / 発注の共有 / バックアップ書き出し が動く
- [ ] 機内モードで「インターネットに接続できません」画面 → 復帰で戻る

## Apple 側

- [ ] Xcode の Signing & Capabilities で Team 選択済み、実機で Run できた
- [ ] App Store Connect にアプリ作成(名前・バンドルID・SKU)
- [ ] App情報: サブタイトル / カテゴリ(ビジネス) / 年齢 4+ / プライバシーポリシー URL
- [ ] 価格: 無料 / 配信国
- [ ] Appのプライバシー: [privacy-labels.md](./privacy-labels.md) の表どおりに入力し「公開」
- [ ] スクリーンショット: iPhone 6.9インチ 3枚以上・iPad 13インチ 3枚以上(ログイン後の実画面)
- [ ] プロモーション用テキスト / 概要 / キーワード / サポート URL / マーケティング URL([metadata.md](./metadata.md))
- [ ] App Review に関する情報: サインインが必要=オン、デモアカウント(サンプル請求書入り)のメール/パスワード
- [ ] メモ欄に [review-notes.md](./review-notes.md) §2 の英文を貼付
- [ ] 連絡先(名前・電話・メール)
- [ ] Xcode で Archive → Upload 済み、ビルドがバージョンに紐付いている
- [ ] 「バージョンのリリース」= 審査承認後に手動
- [ ] 「審査へ提出」

## 承認後

- [ ] 手動リリースを押す(公開日を決める)
- [ ] App Store の URL を LP・招待文・会社サイトに載せる
- [ ] デモ会社を削除する(または残して次回も使う)
- [ ] `docs/appstore/README.md` の表に App Store URL と App ID を追記
