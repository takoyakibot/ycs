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

echo "▶ 1. ローカルビルド"
npm run build
composer install --no-dev --optimize-autoloader

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

echo "✅ デプロイ完了"
