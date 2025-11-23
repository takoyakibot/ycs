# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

YouTubeアーカイブのタイムスタンプ管理システム「歌枠履歴er:D」。YouTubeの動画からタイムスタンプを抽出し、楽曲マスタと紐づけて正規化する機能を提供。

## Tech Stack

- **Backend**: Laravel 10 (PHP 8.1+)
- **Frontend**: Blade + Alpine.js + Tailwind CSS
- **Database**: MySQL (テストはSQLite in-memory)
- **APIs**: YouTube Data API v3, Spotify Web API

## Common Commands

```bash
# Development
npm run dev              # Start Vite dev server
php artisan serve        # Start Laravel dev server

# Build
php artisan cache:clear
php artisan view:clear
php artisan config:clear
php artisan route:clear
npm run build            # Build frontend assets
php artisan cache:clear
php artisan view:clear
php artisan config:clear
php artisan route:clear

# Testing
php artisan test                           # Run all tests
php artisan test --filter=SongControllerTest  # Run specific test class
php artisan test tests/Feature/SongControllerTest.php::test_specific_method  # Run single test

# Code formatting
./vendor/bin/pint        # Format PHP code with Laravel Pint

# Before commit (recommended)
./pre-commit-check.sh    # Clears config cache and runs tests
```

## Architecture

### Core Data Flow

1. YouTube API → `RefreshArchiveService` → archives + ts_items tables
2. `change_list` テーブルが表示状態の**マスター**として機能
3. `RefreshArchiveService::refreshArchives()` が change_list の内容を archives/ts_items の `is_display` に反映

### Key Models & Relationships

- **Channel** → hasMany → **Archive** (via `channel_id`)
- **Archive** → hasMany → **TsItem** (via `video_id`)
- **Song** → hasMany → **TimestampSongMapping** → belongsTo → TsItem

### Display State Logic

表示判定で `change_list` を直接JOINする必要なし。`archives.is_display = 1` かつ `ts_items.is_display = 1` のみ表示。

### Services

- `RefreshArchiveService`: YouTube APIからデータ取得・同期、change_list反映
- `YouTubeService`: YouTube Data API v3のラッパー
- `SpotifyService`: Spotify Web APIのラッパー

### Frontend JavaScript

- `resources/js/channels/archive-list.js`: チャンネルページのアーカイブ一覧
- `resources/js/manage/archives.js`: 管理画面のアーカイブ管理
- `resources/js/songs/normalize.js`: 楽曲正規化画面

## Testing Notes

- テストはSQLite in-memoryを使用 (`phpunit.xml`で設定)
- `RefreshArchiveService`はMySQL用とSQLite用で異なるクエリを使用（ドライバ判定）
- 設定キャッシュが原因で419エラーが出る場合: `php artisan config:clear`

## Database Conventions

- Primary keys use ULID (26 characters)
- `video_id` is YouTube's 11-character video ID
- `ts_items.type`: '1' = 概要欄, '2' = コメント
- `ts_items.ts_text`: タイムスタンプ文字列 (HH:MM:SS形式)
- `ts_items.ts_num`: タイムスタンプ秒数

## Workflow

修正対応は以下のフローで実施:

1. developの最新化
2. 対象ブランチを最新のdevelopにrebase
3. 修正を実施
4. その内容をレビュー
5. 問題があれば修正
6. PRを作成
7. PRをレビュー
8. 指摘事項があれば修正
9. 修正された内容を再度レビュー
10. 問題がなければマージ

### 留意点

- ソースの修正を伴う作業は常にワークフローに従う
- 対応は常に日本語で実施する
- レビューで発見された問題がブランチの範囲外の場合は、PRへの指摘ではなくIssueを追加して対応する（類似のIssueがある場合はそのIssueに追記）
- レビューの指摘事項がブランチの範囲外である場合、Issueを追加してPRとしては対応しない
