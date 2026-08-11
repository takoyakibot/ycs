#!/bin/bash
#
# 本番デプロイスクリプト
#
# 使い方: ./deploy.sh
#
# 処理内容:
#   1. ローカルでビルド（npm run build, composer --no-dev）
#   2. rsync で本番サーバーに転送
#   3. サーバー側で artisan キャッシュクリア（これが重要）
#
# 注意:
#   - ローカルだけで artisan config:clear してもサーバーのキャッシュは消えない
#   - opcache の都合で、ファイル更新後にサーバー側で artisan を実行する必要がある
#

set -e

SERVER="alpacasandbag@v2007.coreserver.jp"
SSH_KEY="$HOME/.ssh/ycs_rsa"
REMOTE_PATH="/home/alpacasandbag/domains/ycs.alpacasandbag.jp/public_html"

# --no-dev で dev 依存を剥がした後に失敗すると、成功時の復元（ステップ4）がスキップされ、
# ローカルが本番向けのまま取り残される（php artisan test が動かなくなる）。
# 失敗に気づけないまま「デプロイしたつもり」になるのを防ぐため、終了時に必ず後始末する。
DEV_DEPS_STRIPPED=0

on_exit() {
  local status=$?

  if [ "$status" -eq 0 ]; then
    return
  fi

  echo ""
  # macOS標準の bash 3.2 は変数展開の直後にマルチバイト文字が続くと壊れるため ${} で囲む
  echo "❌ デプロイが途中で失敗しました（終了コード: ${status}）"
  echo "   本番への反映は完了していません。上のログを確認して再実行してください。"

  if [ "$DEV_DEPS_STRIPPED" -eq 1 ]; then
    echo "▶ ローカルの開発用依存を復元します"
    composer install || echo "⚠ 復元に失敗しました。手動で 'composer install' を実行してください"
  fi
}
trap on_exit EXIT

echo "▶ 1. ローカルビルド"
npm run build
composer install --no-dev --optimize-autoloader
DEV_DEPS_STRIPPED=1

echo "▶ 2. rsync で本番転送"
rsync -avz --exclude-from=".exclude-list" --delete \
  -e "ssh -i $SSH_KEY -p 22" \
  . "$SERVER:$REMOTE_PATH/"

echo "▶ 3. サーバー側でキャッシュクリア"
ssh -i "$SSH_KEY" -p 22 "$SERVER" "cd $REMOTE_PATH && \
  php artisan config:clear && \
  php artisan cache:clear && \
  php artisan route:clear && \
  php artisan view:clear"

echo "▶ 4. ローカルの開発用依存を復元"
composer install
DEV_DEPS_STRIPPED=0

echo "✅ デプロイ完了"
