#!/bin/bash
#
# 本番デプロイスクリプト
#
# 使い方: ./deploy.sh
#
# 処理内容:
#   1. ローカルでビルド（npm run build, composer --no-dev）
#   2. rsync で本番サーバーに転送
#   3. サーバー側で artisan キャッシュクリア → マイグレーション
#
# 注意:
#   - ローカルだけで artisan config:clear してもサーバーのキャッシュは消えない
#   - opcache の都合で、ファイル更新後にサーバー側で artisan を実行する必要がある
#   - サーバーの PATH 上の php は 8.1（サポート終了）なので、Web と同じバージョンの
#     PHP を絶対パスで指定する。コントロールパネルのドメイン別PHPバージョンを変えたら
#     REMOTE_PHP と crontab の PHP も合わせて変えること
#

set -e

SERVER="alpacasandbag@v2007.coreserver.jp"
SSH_KEY="$HOME/.ssh/ycs_rsa"
REMOTE_PATH="/home/alpacasandbag/domains/ycs.alpacasandbag.jp/public_html"
REMOTE_PHP="/usr/local/php84/bin/php"

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

echo "▶ 1.5. 環境依存ファイルの検知・削除"
for cache_file in bootstrap/cache/config.php bootstrap/cache/routes-v7.php; do
  if [ -f "$cache_file" ]; then
    echo "⚠ $cache_file が存在します。ローカル設定が本番に送られる可能性がありました。削除して続行します。"
    rm "$cache_file"
  fi
done
if [ -f "public/hot" ]; then
  echo "⚠ public/hot が存在します（Vite devサーバー用）。本番に送ると全アセットが読み込めなくなるため削除します。"
  rm "public/hot"
fi

echo "▶ 2. rsync で本番転送"
rsync -avz --exclude-from=".exclude-list" --delete \
  -e "ssh -i $SSH_KEY -p 22" \
  . "$SERVER:$REMOTE_PATH/"

echo "▶ 3. サーバー側でキャッシュクリアとマイグレーション"
ssh -i "$SSH_KEY" -p 22 "$SERVER" "bash -s" <<REMOTE_SCRIPT
set -e
cd "$REMOTE_PATH"

# .exclude-list で storage/framework を転送対象から外しているため、
# 新しい環境ではディレクトリが無い。無ければ作る
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views

"$REMOTE_PHP" artisan config:clear
"$REMOTE_PHP" artisan cache:clear
"$REMOTE_PHP" artisan route:clear
"$REMOTE_PHP" artisan view:clear

echo "--- 未適用のマイグレーション"
"$REMOTE_PHP" artisan migrate:status | grep -i pending || echo "（なし）"

"$REMOTE_PHP" artisan migrate --force
REMOTE_SCRIPT

echo "▶ 4. ローカルの開発用依存を復元"
composer install
DEV_DEPS_STRIPPED=0

echo "✅ デプロイ完了"
