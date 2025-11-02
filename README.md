# 歌枠履歴er:D

YouTubeアーカイブのタイムスタンプ管理システム

## プロジェクト概要

このプロジェクトは、YouTubeアーカイブのタイムスタンプを収集・管理し、楽曲マスタと紐づけて正規化する機能を提供するWebアプリケーションです。

## 主な機能

### チャンネル管理
- YouTubeチャンネルの登録・管理
- アーカイブ（動画）の自動取得
- タイムスタンプの抽出と管理

### タイムスタンプ正規化
- 楽曲マスタ(songs)の作成・管理
- タイムスタンプと楽曲マスタの紐づけ
- Spotify API連携による楽曲情報の検索・登録
- 楽曲ではないタイムスタンプのフラグ管理

## 技術スタック

- **バックエンド**: Laravel 10.10
- **フロントエンド**: Blade + Alpine.js + Tailwind CSS
- **データベース**: MySQL
- **API連携**: YouTube Data API v3, Spotify Web API

## セットアップ

### 必要な環境

- PHP 8.1以上
- Composer
- MySQL 5.7以上
- Node.js & npm

### インストール手順

1. リポジトリをクローン
```bash
git clone <repository-url>
cd ycs
```

2. 依存関係のインストール
```bash
composer install
npm install
```

3. 環境設定
```bash
cp .env.example .env
php artisan key:generate
```

4. `.env` ファイルを編集してデータベース接続とAPI認証情報を設定
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database_name
DB_USERNAME=your_username
DB_PASSWORD=your_password

SPOTIFY_CLIENT_ID=your_spotify_client_id
SPOTIFY_CLIENT_SECRET=your_spotify_client_secret
```

5. データベースマイグレーション
```bash
php artisan migrate
```

6. フロントエンドのビルド
```bash
npm run build
# または開発環境で
npm run dev
```

7. アプリケーションの起動
```bash
php artisan serve
```

### Spotify API設定

1. [Spotify Developer Dashboard](https://developer.spotify.com/dashboard)にアクセス
2. アプリを作成してClient IDとClient Secretを取得
3. `.env`ファイルに認証情報を設定

## データベース構造

### 表示状態管理の仕様

本システムでは、archives（動画）とts_items（タイムスタンプ）の表示状態を`change_list`テーブルで管理しています。

#### 重要な仕様
- **change_list**: 表示状態の変更履歴を管理する**マスターテーブル**
  - `video_id` + `comment_id IS NULL`: 動画レベルの表示状態
  - `video_id` + `comment_id`: タイムスタンプレベルの表示状態
- **archives.is_display**: 動画の表示状態（change_listの内容が反映済み）
- **ts_items.is_display**: タイムスタンプの表示状態（change_listの内容が反映済み）

#### データフロー
1. YouTubeから最新データを取得（is_displayはデフォルト値）
2. `RefreshArchiveService`が`change_list`の内容を`archives`と`ts_items`に反映
3. 表示判定は`archives.is_display`と`ts_items.is_display`のみを確認すれば良い

#### 実装上の注意
- 表示判定で`change_list`を直接JOINする必要はありません
- `archives.is_display = 1`かつ`ts_items.is_display = 1`のみ表示されます
- 変更履歴は`RefreshArchiveService::refreshArchives()`で自動的に反映されます

### songs テーブル
楽曲マスタを管理するテーブル

| カラム名 | 型 | 説明 |
|---------|-----|------|
| id | string(26) | ULID |
| title | string | 楽曲名 |
| artist | string | アーティスト名 |
| spotify_track_id | string(22) | Spotify Track ID (nullable) |
| is_not_song | boolean | 楽曲ではないフラグ |
| created_at | timestamp | 作成日時 |
| updated_at | timestamp | 更新日時 |

### archives テーブル
動画情報を管理するテーブル

| カラム名 | 型 | 説明 |
|---------|-----|------|
| id | string(26) | ULID |
| channel_id | string | チャンネルID |
| video_id | string(11) | YouTube動画ID |
| title | string | 動画タイトル |
| thumbnail | string | サムネイルURL |
| is_public | boolean | 公開状態 |
| is_display | boolean | 表示フラグ（change_listの内容が反映済み） |
| published_at | timestamp | 公開日時 |
| comments_updated_at | timestamp | コメント更新日時 |

### ts_items テーブル
タイムスタンプ情報を管理するテーブル

| カラム名 | 型 | 説明 |
|---------|-----|------|
| id | string(26) | ULID |
| video_id | string(11) | YouTube動画ID |
| comment_id | string(26) | コメントID (nullable) |
| type | enum('1','2') | 1:概要欄, 2:コメント |
| ts_text | string(8) | タイムスタンプテキスト (HH:MM:SS) |
| ts_num | integer | タイムスタンプ秒数 |
| text | string | タイムスタンプのテキスト |
| is_display | boolean | 表示フラグ（change_listの内容が反映済み） |

### change_list テーブル
表示状態の変更履歴を管理するマスターテーブル

| カラム名 | 型 | 説明 |
|---------|-----|------|
| id | bigint | 主キー |
| channel_id | string | チャンネルID |
| video_id | string(11) | YouTube動画ID |
| comment_id | string(26) | コメントID (nullable、nullの場合は動画レベルの変更) |
| is_display | boolean | 表示フラグ |

## 使い方

### タイムスタンプ正規化画面

1. ログイン後、ナビゲーションから「タイムスタンプ正規化」を選択
2. タイムスタンプ一覧が表示されます

#### 楽曲マスタの登録

**方法1: Spotify検索から登録**
1. 検索キーワードを入力して検索
2. 検索結果から楽曲を選択
3. 自動的に楽曲名・アーティスト名が入力されます
4. 「楽曲マスタに登録」ボタンをクリック

**方法2: 手動入力**
1. 楽曲名とアーティスト名を直接入力
2. 「楽曲ではない」チェックボックスで楽曲でないことを示すことも可能
3. 「楽曲マスタに登録」ボタンをクリック

#### タイムスタンプの紐づけ

1. タイムスタンプ一覧から紐づけたいタイムスタンプをチェック
2. 紐づける楽曲マスタをドロップダウンから選択
3. 「選択したタイムスタンプを紐づける」ボタンをクリック

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com/)**
- **[Tighten Co.](https://tighten.co)**
- **[WebReinvent](https://webreinvent.com/)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel/)**
- **[Cyber-Duck](https://cyber-duck.co.uk)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Jump24](https://jump24.co.uk)**
- **[Redberry](https://redberry.international/laravel/)**
- **[Active Logic](https://activelogic.com)**
- **[byte5](https://byte5.de)**
- **[OP.GG](https://op.gg)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
