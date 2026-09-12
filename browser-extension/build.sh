#!/bin/bash
#
# ブラウザ拡張のビルド・パッケージングスクリプト
#
# 使い方:
#   ./build.sh              # 管理者版 + 一般版の両方をビルド
#   ./build.sh general      # 一般版のみビルド・パッケージング
#   ./build.sh admin        # 管理者版のみビルド
#
# ビルド結果:
#   dist/admin/    — 管理者版（全機能）
#   dist/general/  — 一般版（公開配布用）
#
# Chrome での読み込み:
#   chrome://extensions → デベロッパーモード ON
#   → 「パッケージ化されていない拡張機能を読み込む」→ dist/general/ を選択
#   → 変更後は ./build.sh general を再実行し、拡張の「更新」ボタンを押す

set -e
cd "$(dirname "$0")"

EDITION="${1:-all}"

if [[ "$EDITION" != "all" && "$EDITION" != "admin" && "$EDITION" != "general" ]]; then
  echo "使い方: ./build.sh [all|admin|general]"
  exit 1
fi

echo "▶ rollup ビルド"
npx rollup -c

ADMIN_FILES=(
  content.js
  content-chat-delay.js
  content-google.js
  content-ycs.js
  background.js
  popup.html
  popup.js
  offscreen.html
  offscreen.js
  page-bridge.js
  manifest.json
)

GENERAL_FILES=(
  background.js
  popup.html
  popup.js
)

build_admin() {
  echo "▶ 管理者版パッケージング → dist/admin/"
  rm -rf dist/admin
  mkdir -p dist/admin

  for f in "${ADMIN_FILES[@]}"; do
    cp "$f" "dist/admin/$f"
  done
  cp -r icons dist/admin/

  local size
  size=$(wc -c < dist/admin/content.js | tr -d ' ')
  echo "  content.js: ${size} bytes"
  echo "✅ 管理者版: dist/admin/"
}

build_general() {
  echo "▶ 一般版パッケージング → dist/general/"
  rm -rf dist/general
  mkdir -p dist/general

  cp content-general.js dist/general/content.js
  cp manifest.general.json dist/general/manifest.json

  for f in "${GENERAL_FILES[@]}"; do
    cp "$f" "dist/general/$f"
  done
  cp -r icons dist/general/

  local size
  size=$(wc -c < dist/general/content.js | tr -d ' ')
  echo "  content.js: ${size} bytes"
  echo "✅ 一般版: dist/general/"
}

case "$EDITION" in
  all)
    build_admin
    build_general
    ;;
  admin)
    build_admin
    ;;
  general)
    build_general
    ;;
esac

echo ""
echo "Chrome で読み込む: chrome://extensions → 「パッケージ化されていない拡張機能を読み込む」"
echo "  管理者版: $(pwd)/dist/admin/"
echo "  一般版:   $(pwd)/dist/general/"
