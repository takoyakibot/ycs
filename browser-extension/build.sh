#!/bin/bash
#
# ブラウザ拡張のビルド・パッケージングスクリプト
#
# 使い方: ./build.sh
#
# ビルド結果:
#   dist/admin/    — 管理者版（全機能）
#   dist/general/  — 一般版（公開配布用）
#
# Chrome での読み込み:
#   chrome://extensions → デベロッパーモード ON
#   → 「パッケージ化されていない拡張機能を読み込む」→ dist/general/ を選択
#   → 変更後は ./build.sh を再実行し、拡張の「更新」ボタンを押す

set -e
cd "$(dirname "$0")"

echo "▶ rollup ビルド"
npx rollup -c

echo "▶ 管理者版パッケージング → dist/admin/"
rm -rf dist/admin
mkdir -p dist/admin
for f in content.js content-chat-delay.js content-google.js content-ycs.js \
         background.js popup.html popup.js offscreen.html offscreen.js \
         page-bridge.js manifest.json; do
  cp "$f" "dist/admin/$f"
done
cp -r icons dist/admin/

echo "▶ 一般版パッケージング → dist/general/"
rm -rf dist/general
mkdir -p dist/general
cp content-general.js dist/general/content.js
cp manifest.general.json dist/general/manifest.json
# 一般版で到達しない分岐（tabCapture / offscreen / Claude API / 管理者向け設定欄など）を
# 除いたもの（rollup.config.mjs 参照）
cp background-general.js dist/general/background.js
cp popup-general.js dist/general/popup.js
cp popup-general.html dist/general/popup.html
cp page-bridge.js dist/general/page-bridge.js
# manifest が参照するアイコンだけを入れる（icon.svg などは一般版で使わない）
mkdir -p dist/general/icons
for size in 16 48 128; do
  cp "icons/icon${size}.png" "dist/general/icons/icon${size}.png"
done

echo ""
echo "  管理者版 content.js: $(wc -c < dist/admin/content.js | tr -d ' ') bytes"
echo "  一般版   content.js: $(wc -c < dist/general/content.js | tr -d ' ') bytes"
echo ""
echo "✅ ビルド完了"
echo "  管理者版: $(pwd)/dist/admin/"
echo "  一般版:   $(pwd)/dist/general/"
