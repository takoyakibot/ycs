# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Note

ユーザーは音声入力を使用していることがあるため、入力に誤変換・誤認識が含まれる場合があります。文脈から意図を汲み取って対応してください。

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

**重要**: 新しいJSファイルを追加する際は、必ず `vite.config.js` の `input` 配列にも追加すること。追加しないとビルド時にマニフェストに含まれず、本番環境で500エラーが発生する。

### Data Flow: タイムスタンプ → 楽曲マスタ

1. **タイムスタンプ取得**
   - YouTube API → `RefreshArchiveService` → `ts_items` テーブル
   - `ts_items.text`: 元テキスト（ユーザーが入力した曲名）
   - `ts_items.type`: '1' = 概要欄, '2' = コメント, '3' = カバー曲

2. **正規化処理**
   - `TsItem::saving` イベントで自動実行
   - `TextNormalizer::normalize()` を適用
   - 結果: `ts_items.normalized_text`（全角→半角、記号統一、小文字化）

3. **楽曲マッピング（3テーブルJOIN）**
   ```sql
   ts_items
     LEFT JOIN timestamp_song_mappings ON ts_items.normalized_text = timestamp_song_mappings.normalized_text
     LEFT JOIN songs ON timestamp_song_mappings.song_id = songs.id
   ```
   - `timestamp_song_mappings.is_not_song`: 「楽曲ではない」フラグ
   - `songs`: 楽曲マスタ（Spotify Track IDなど保持）

4. **検索フロー**
   - 検索: `ts_items.text LIKE '%query%'` (元テキストで検索)
   - 結合: `normalized_text` でマッピングテーブルと結合

5. **表示フロー**
   - `TimestampService::getTimestampsWithMapping()`
   - 3テーブルJOIN → フィルター → ソート → ページング

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
- `normalized_text` カラムは **`utf8mb4_bin`** を指定すること（`ts_items` / `timestamp_song_mappings` / `timestamp_decompositions`）
  - デフォルトの `utf8mb4_unicode_ci` は補助面（絵文字）に重みを持たず「🎵A」と「🎶A」を同値と判定するため、バイト完全一致で扱うアプリ側と結果がずれる
  - 同値扱いされるのは絵文字だけではない。半角/全角カナ（`ｲｴｽﾀﾃﾞｲ` = `イエスタデイ`）とアクセント記号（`cafe` = `café`）も同値で、これらは `TextNormalizer::normalize()` が揃えない（`mb_convert_kana` に `'as'` しか渡していない）
  - **揃え忘れはエラーにならない。** 同一 charset で片方が `_bin` の場合、MySQL は `_bin` を優先するため JOIN は通り、意味論が黙って変わる。`Illegal mix of collations` が出るのは非バイナリ照合同士が食い違う場合のみ
  - `utf8mb4_bin` は PAD SPACE。末尾スペースの有無は DB 側では無視される（NO PAD が必要なら `utf8mb4_0900_bin`）
  - `MODIFY` で照合順序を指定するときは、`CHARACTER SET` / `COLLATE` を**型の直後・NULL 可否より前**に置くこと。順序を誤ると `ERROR 1064` になる。テストは SQLite なのでこの誤りは実行では検知できない（`tests/Unit/Migrations/ChangeNormalizedTextCollationTest.php` で生成SQLを固定している）
  - `songs.normalized_title` / `normalized_artist` も `utf8mb4_bin` に変更済み（#643）

## Production Server

```bash
ssh -i ~/.ssh/alpacasandbag_app2_rsa -p 22 alpacasandbag@v2007.coreserver.jp
```

- ホスティング: CORESERVER v2007
- アプリパス: `~/domains/ycs.alpacasandbag.jp/public_html/`
- artisan実行例: `cd ~/domains/ycs.alpacasandbag.jp/public_html && php artisan tinker --execute="..."`

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
9. ユーザーに変更内容を確認させる。指摘事項があれば修正し、再度ユーザーに確認させる。これを指摘事項がなくなるまで続ける
10. 修正された内容を再度レビュー
11. 問題がなければマージ

### 留意点

- ソースの修正を伴う作業は常にワークフローに従う
- 基本的にdevelopには直接コミットしないこと
- ワークフローの内容は、「ユーザー」の記載がない項目はclaudeが担当する
- 対応は常に日本語で実施する
- レビューで発見された問題がブランチの範囲外の場合は、PRへの指摘ではなくIssueを追加して対応する（類似のIssueがある場合はそのIssueに追記）
- レビューの指摘事項がブランチの範囲外である場合、Issueを追加してPRとしては対応しない
