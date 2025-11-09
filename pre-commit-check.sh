#!/bin/bash

# Git コミット前チェックスクリプト

echo "🔍 Pre-commit checks starting..."
echo ""

# 1. 設定キャッシュをクリア
# Note: config:cacheを実行した環境でテストを実行すると、
# APP_ENVがhardcodeされテストが失敗する問題を防ぐため (Issue #141)
echo "1️⃣ Clearing config cache..."
OUTPUT=$(php artisan config:clear --quiet 2>&1)
EXIT_CODE=$?

if [ $EXIT_CODE -ne 0 ]; then
    echo "❌ Failed to clear config cache!"
    echo "   Error details:"
    echo "$OUTPUT"
    echo "   Please ensure Laravel is properly installed and bootstrap/cache is writable."
    exit 1
fi
echo "✅ Config cache cleared!"
echo ""

# 2. PHPUnit テストを実行
echo "2️⃣ Running PHPUnit tests..."

# .env.testingの存在チェック（警告のみ、ブロックはしない）
if [ ! -f .env.testing ]; then
    echo "⚠️  WARNING: .env.testing が存在しません"
    echo "   phpunit.xmlのデフォルト設定（インメモリDB）を使用します"
    echo "   新しいワークツリーの場合は以下を実行してください:"
    echo "   cp .env.testing.example .env.testing"
    echo "   php artisan key:generate --env=testing"
    echo ""
fi

php artisan test
if [ $? -ne 0 ]; then
    echo "❌ Tests failed! Please fix before committing."
    exit 1
fi
echo "✅ Tests passed!"
echo ""

# 3. Laravel Pint (コードスタイル) をチェック
echo "3️⃣ Checking code style with Laravel Pint..."
./vendor/bin/pint --test
if [ $? -ne 0 ]; then
    echo "❌ Code style issues found! Run './vendor/bin/pint' to fix them."
    exit 1
fi
echo "✅ Code style is good!"
echo ""

# 4. フロントエンドビルドをチェック
echo "4️⃣ Building frontend assets..."
npm run build
if [ $? -ne 0 ]; then
    echo "❌ Frontend build failed! Please fix before committing."
    exit 1
fi
echo "✅ Frontend build successful!"
echo ""

echo "🎉 All checks passed! You can safely commit your changes."
echo "✨ Pre-commit hooks are working correctly!"