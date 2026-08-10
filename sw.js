// 問屋さん サービスワーカー
// 方針: 通信できるときは常に最新を取りに行き、成功したものを控えとして保存。
// 圏外や障害時だけ控えを表示する(古い画面が残り続けない設計)。
// API(api/ 配下)は保存しない — 価格データは常にサーバーの最新を使う。
const CACHE = "tonya-shell-v1";

self.addEventListener("install", () => {
  self.skipWaiting();
});

self.addEventListener("activate", (e) => {
  e.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener("fetch", (e) => {
  const url = new URL(e.request.url);
  if (e.request.method !== "GET") return;
  if (url.pathname.indexOf("/api/") >= 0) return; // データは素通し
  e.respondWith(
    fetch(e.request)
      .then((res) => {
        if (res.ok && url.origin === self.location.origin) {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(e.request, copy));
        }
        return res;
      })
      .catch(() => caches.match(e.request, { ignoreSearch: true }))
  );
});
