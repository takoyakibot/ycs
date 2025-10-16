#!/bin/bash

# Git コミット前チェックスクリプト

echo "🔍 Pre-commit checks starting..."
echo ""

# 1. PHPUnit テストを実行
echo "1️⃣ Running PHPUnit tests..."
php artisan test
if [ $? -ne 0 ]; then
    echo "❌ Tests failed! Please fix before committing."
    exit 1
fi
echo "✅ Tests passed!"
echo ""

# 2. Laravel Pint (コードスタイル) をチェック
echo "2️⃣ Checking code style with Laravel Pint..."
./vendor/bin/pint --test
if [ $? -ne 0 ]; then
    echo "❌ Code style issues found! Run './vendor/bin/pint' to fix them."
    exit 1
fi
echo "✅ Code style is good!"
echo ""

# 3. フロントエンドビルドをチェック
echo "3️⃣ Building frontend assets..."
npm run build
if [ $? -ne 0 ]; then
    echo "❌ Frontend build failed! Please fix before committing."
    exit 1
fi
echo "✅ Frontend build successful!"
echo ""

echo "🎉 All checks passed! You can safely commit your changes."
echo "✨ Pre-commit hooks are working correctly!"