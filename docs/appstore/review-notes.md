# 審査員向けメモ(App Review Information)

## 1. デモアカウントの用意(提出直前)

審査員は日本語の分からない人が担当することも多い。**サンプルの請求書が数枚入った状態**の
専用会社を作っておくと、機能をすぐ見てもらえて「Web サイトを包んだだけ(4.2)」の疑いも避けやすい。

1. アプリ(または <https://tonyasan.jp/>)で「新規登録」→ 会社名 `App Review Demo`、
   担当者名 `Reviewer`、メールは受信できる社用アドレス(例: `appreview@kyoei-sakai.com` の転送等)、
   電話は会社代表番号、パスワードは英数 12 文字程度
2. 確認コードで登録を完了 → ホームで **サンプルの請求書 PDF を 3〜5 枚**取り込む
   (実在の取引先名が入るのが気になる場合は、社名を伏せた見本を使う)
3. いくつか検索して、発注カートにも 2〜3 品目入れておく
4. App Store Connect → App Review に関する情報 → **サインインが必要: オン** → 上のメール/パスワードを記入
5. 承認後、この会社は「導入社の管理」から削除してよい(次回提出時にまた作る)

## 2. メモ欄に貼る英文(そのまま)

```
Thank you for reviewing "問屋さん" (Tonya-san).

WHAT THIS APP IS
A B2B tool for Japanese construction companies. Staff photograph supplier invoices /
quotes; the app extracts line items (material, quantity, unit price, supplier) with AI,
and builds a searchable, company-wide price & purchase history. It also has an order
cart, price-trend summaries, and cloud storage of the original documents.
The service is free (no in-app purchases, no ads).

DEMO ACCOUNT (already contains sample invoices)
  Email:    ______________________
  Password: ______________________
Sign in on the first screen. Data is per company; the demo company is isolated.

HOW TO TRY THE MAIN FEATURES
1. Home ("ホーム") -> tap the import area -> choose Camera / Photo Library / Files.
   Pick a PDF or a photo of an invoice; the AI reads it in ~10-20 seconds.
   (In the Simulator, use "Files". On a device, Camera works.)
2. Search box at the top -> type e.g. "塗料" (paint) or "石膏ボード" (gypsum board)
   -> results show unit prices, suppliers, and price history.
3. Tap a result -> price history chart, AI notes, "カートに入れる" (add to cart).
4. Cart ("カート") -> "発注文を作る" (create order text) -> "共有…" opens the iOS share sheet.
5. "データ管理" (Data management) -> account section: change password, staff accounts,
   and "アカウントの削除" (Delete account) — self-service account deletion is provided
   (Guideline 5.1.1 v).

NATIVE INTEGRATION
The UI is rendered from our server (https://tonyasan.jp/), wrapped in a native shell that
adds: camera / photo library capture for documents, the iOS share sheet for orders and
exports (save to Files, Mail, LINE...), an app icon & launch screen, and an offline screen.

PRIVACY
Privacy policy: https://tonyasan.jp/privacy.html
Documents the user imports are sent to Google (Gemini API) / Anthropic (Claude API) only
for text extraction, as disclosed in the policy. No advertising SDKs, no tracking.

If anything is unclear, please contact us at the phone number / email in the contact
fields — we can respond quickly in English or Japanese.
```

## 3. 日本語での要点(自分用・電話が来たとき)

- 建設会社向けの社内業務ツール。請求書を撮る → AI が明細化 → 会社全員で価格検索。無料・広告なし
- ログイン必須なのでデモ会社を渡している(サンプル請求書入り)
- 「Web を包んだだけ」ではない: カメラ取り込み・共有シート・オフライン画面・アイコン/起動画面
- アカウント削除は 設定 → アカウント の一番下
- 書類は AI 読み取りのため Google / Anthropic に送る旨をポリシーに明記
