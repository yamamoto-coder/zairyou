import type { CapacitorConfig } from '@capacitor/cli';

// 問屋さん iOS アプリ(Capacitor)。
// リモート Web ラッパー: WKWebView が本番の https://tonyasan.jp/ を直接読み込む。
// 画面本体(index.html)は今まで通り GitHub Pages / tonyasan.jp に配置したものを使うので、
// アプリの再審査なしに Web 側の更新がそのまま届く。
//
// ネイティブ側の役割:
//  - ホーム画面アイコン・起動画面(スプラッシュ)
//  - カメラ / 写真ライブラリから書類を取り込む(<input type="file"> が OS のピッカーを開く)
//  - 共有シート(発注文・紹介文・バックアップの JSON) — index.html が window.Capacitor を検出して使う
//  - 圏外時のフォールバック画面(www/index.html)
const config: CapacitorConfig = {
  appId: 'jp.tonyasan.app',
  appName: '問屋さん',
  // server.url を使うため実際にはバンドルは読み込まれないが、Capacitor が webDir を要求する。
  // 圏外・接続不能時に出す最小のフォールバックページを置く。
  webDir: 'www',
  server: {
    url: 'https://tonyasan.jp/',
    iosScheme: 'https',
    cleartext: false,
    // ここに挙げたホストだけが WebView 内で開く。それ以外(LINE・Google 検索など)は
    // 端末のブラウザ/該当アプリで開く。
    allowNavigation: ['tonyasan.jp', 'www.tonyasan.jp'],
    // 圏外などで本番が読めないときは www/index.html を表示する
    errorPath: 'index.html',
  },
  ios: {
    contentInset: 'automatic',
    limitsNavigationsToAppBoundDomains: false,
    scrollEnabled: true,
    // 画面本体は自前配信なので WebView のデバッグは切っておく
    webContentsDebuggingEnabled: false,
  },
  plugins: {
    SplashScreen: {
      launchShowDuration: 1500,
      launchAutoHide: true,
      launchFadeOutDuration: 250,
      backgroundColor: '#ffffff',
      showSpinner: false,
    },
    StatusBar: {
      style: 'LIGHT',
      backgroundColor: '#ffffff',
    },
  },
};

export default config;
