#!/bin/bash

# Git Hooks セットアップスクリプト
# 新しいチームメンバーが実行してGit Hooksを設定

echo "🔧 Setting up Git Hooks..."

# git-hooks フォルダから .git/hooks にコピー
if [ -f "git-hooks/pre-commit" ]; then
    cp git-hooks/pre-commit .git/hooks/pre-commit
    chmod +x .git/hooks/pre-commit
    echo "✅ pre-commit hook installed"
else
    echo "❌ git-hooks/pre-commit not found"
fi

# 他のhooksがあれば追加
if [ -f "git-hooks/pre-push" ]; then
    cp git-hooks/pre-push .git/hooks/pre-push
    chmod +x .git/hooks/pre-push
    echo "✅ pre-push hook installed"
fi

echo "🎉 Git Hooks setup complete!"
echo ""
echo "💡 Tip: Run this script again if you update the hooks"