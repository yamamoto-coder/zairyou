#!/bin/zsh
# 問屋さん iOS — シミュレータの起動とスクリーンショット撮影
#
#   ./scripts/sim.sh boot "iPhone 17 Pro Max"   # 端末を起動し、アプリをビルドして入れて起動する
#   ./scripts/sim.sh shot iphone-01-home         # 今表示中の画面を docs/appstore/screenshots/ に保存する
#   ./scripts/sim.sh list                        # 使える端末名の一覧
#
# スクリーンショットは端末の実解像度で保存されるので、そのまま App Store Connect に上げられる
# (iPhone 17 Pro Max = 1320×2868 / iPad Pro 13-inch = 2064×2752)。
set -euo pipefail

HERE="$(cd "$(dirname "$0")/.." && pwd)"           # native/
REPO="$(cd "$HERE/.." && pwd)"                      # リポジトリ直下
OUT="$REPO/docs/appstore/screenshots"
DD="${TMPDIR:-/tmp}/tonyasan-derived"
BUNDLE_ID="jp.tonyasan.app"

cmd="${1:-}"
case "$cmd" in
  list)
    xcrun simctl list devices available | grep -E "iPhone|iPad"
    ;;
  boot)
    dev="${2:-iPhone 17 Pro Max}"
    echo "▶ ビルド(シミュレータ用・署名なし)"
    xcodebuild -project "$HERE/ios/App/App.xcodeproj" -scheme App -configuration Debug \
      -sdk iphonesimulator -destination "platform=iOS Simulator,name=$dev" \
      -derivedDataPath "$DD" CODE_SIGNING_ALLOWED=NO -quiet build
    echo "▶ 起動: $dev"
    udid="$(xcrun simctl list devices available | grep -F "$dev (" | head -1 | sed -E 's/.*\(([0-9A-F-]{36})\).*/\1/')"
    [ -n "$udid" ] || { echo "端末が見つかりません: $dev(./scripts/sim.sh list で確認)"; exit 1; }
    xcrun simctl boot "$udid" 2>/dev/null || true
    xcrun simctl bootstatus "$udid" -b >/dev/null
    open -a Simulator --args -CurrentDeviceUDID "$udid"
    xcrun simctl install "$udid" "$DD/Build/Products/Debug-iphonesimulator/App.app"
    xcrun simctl terminate "$udid" "$BUNDLE_ID" 2>/dev/null || true
    xcrun simctl launch "$udid" "$BUNDLE_ID" >/dev/null
    echo "✓ 起動しました。Simulator の画面でログインし、撮りたい画面を出したら ./scripts/sim.sh shot <名前>"
    ;;
  shot)
    name="${2:-shot-$(date +%H%M%S)}"
    mkdir -p "$OUT"
    xcrun simctl io booted screenshot "$OUT/$name.png" >/dev/null
    sips -g pixelWidth -g pixelHeight "$OUT/$name.png" | tail -2 | tr -s ' ' | tr '\n' ' '
    echo "→ $OUT/$name.png"
    ;;
  *)
    sed -n 2,9p "$0"
    exit 1
    ;;
esac
