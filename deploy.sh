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
#   - 本番CLIのPHPには psr 拡張が入っており、composer の psr/log と衝突して
#     artisan が起動できない（Monolog\Logger の宣言が互換でないと Fatal になる）。
#     システム側の ini は編集できないため、psr の行だけ除いたコピーを作って
#     PHP_INI_SCAN_DIR で読ませる。scan dir を空にすると pdo_mysql や mbstring も
#     外れてしまうので、コピーを使う必要がある
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

echo "▶ 3. サーバー側でキャッシュクリアとマイグレーション"
ssh -i "$SSH_KEY" -p 22 "$SERVER" "bash -s" <<REMOTE_SCRIPT
set -e
cd "$REMOTE_PATH"

# psr 拡張を除いた ini を用意する（詳細は冒頭の注意を参照）。
# ini のパスは PHP のバージョンで変わるため php --ini から引く
ini_src=\$(php --ini 2>/dev/null | grep -oE '/[^ ,]*alt_php\.ini' | head -1)

if [ -n "\$ini_src" ] && [ -f "\$ini_src" ]; then
  mkdir -p "\$HOME/php-ini-nopsr"
  grep -v '^extension=psr.so' "\$ini_src" > "\$HOME/php-ini-nopsr/alt_php.ini"
  export PHP_INI_SCAN_DIR="\$HOME/php-ini-nopsr"
fi

# .exclude-list で storage/framework を転送対象から外しているため、
# 新しい環境ではディレクトリが無い。無ければ作る
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views

php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

echo "--- 未適用のマイグレーション"
php artisan migrate:status | grep -i pending || echo "（なし）"

php artisan migrate --force
REMOTE_SCRIPT

echo "▶ 4. ローカルの開発用依存を復元"
composer install
DEV_DEPS_STRIPPED=0

echo "✅ デプロイ完了"
