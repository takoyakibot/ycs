# チャンネル一覧画面リファクタリング計画

## 概要

チャンネル一覧画面（アーカイブ・タイムスタンプ）は機能追加により複雑化している。
本ドキュメントは今後のリファクタリング方針を記録する。

## 現状分析

### 複雑度評価

| 項目 | 評価 | 説明 |
|------|------|------|
| JavaScript複雑度 | 3/10 | 1149行の巨大ファイル |
| Blade可読性 | 4/10 | 深いネストと条件分岐 |
| コード重複 | 中程度 | 特にページネーション |

### 問題のあるファイル

| ファイル | 行数 | 問題点 |
|---------|------|--------|
| `resources/js/channels/archive-list.js` | 1149 | 11以上の責務が集中 |
| `resources/views/channels/partials/timestamps-tab.blade.php` | 312 | 複雑な条件分岐 |
| `resources/views/channels/partials/distribution-panel.blade.php` | 138 | 配信サービス個別定義 |

---

## 主要な問題点

### 1. archive-list.js の「神クラス」化

**現状**: 以下の責務が1ファイルに集中

- UI状態管理（activeTab, loading等）
- アーカイブデータ取得・表示
- タイムスタンプデータ取得・表示
- 検索・フィルター処理（サジェスト含む）
- YouTube IFrame API管理
- 動画プレイヤー制御（ドラッグ可能）
- 自動再抽選ロジック
- フェードイン・アウト制御
- 報告機能
- 配信サービスリンク生成
- URL状態管理・復元

### 2. ページネーションの重複

- タイムスタンプタブ: 上下に同じコード（計60行以上）をインライン記述
- アーカイブタブ: `<x-pagination>` コンポーネント使用
- 実装が不統一

### 3. 配信サービスボタンの個別定義

```javascript
// 5つの個別メソッドが存在
getSpotifyUrl(song) { ... }
getAppleMusicUrl(song) { ... }
getYouTubeMusicUrl(song) { ... }
getAmazonMusicUrl(song) { ... }
getLineMusicUrl(song) { ... }
```

Blade側も5つ個別に記述しており、配列でループ可能な構造になっていない。

### 4. 検索ボックスのタブ分岐

同じ `<div>` 内で `x-if` による分岐:
- アーカイブ用: テキスト入力 + タイムスタンプフィルタードロップダウン
- タイムスタンプ用: テキスト入力 + サジェストドロップダウン

---

## リファクタリング計画

### Phase 1: JavaScript分割（優先度: 高）

#### 1-1. 動画プレイヤーの分離

**新規ファイル**: `resources/js/channels/video-player.js`

抽出対象:
- `youtubePlayer` 関連プロパティ
- `initPlayer()`, `loadAndPlayVideo()`, `playVideo()`, `pauseVideo()`
- ドラッグ機能（`startDrag`, `onDrag`, `stopDrag`）
- プレイヤー位置・サイズ管理

#### 1-2. 自動再抽選ロジックの分離

**新規ファイル**: `resources/js/channels/random-player.js`

抽出対象:
- `playRandomTimestamp()`
- `startReshuffleMonitor()`, `stopReshuffleMonitor()`
- フェードイン・アウト制御
- `autoReshuffle` 状態管理

#### 1-3. 検索・フィルター処理の整理

**新規ファイル**: `resources/js/channels/search-filter.js`

抽出対象:
- 検索サジェスト機能
- 頭文字フィルター処理
- 検索履歴管理

### Phase 2: Bladeコンポーネント化（優先度: 中）

#### 2-1. ページネーションの統一

タイムスタンプタブのページネーションを `<x-pagination>` に置き換え。

**変更ファイル**:
- `resources/views/channels/partials/timestamps-tab.blade.php`
- `resources/views/components/pagination.blade.php`（必要に応じて拡張）

#### 2-2. タイムスタンプカードコンポーネント

**新規ファイル**: `resources/views/components/timestamp-card.blade.php`

抽出対象:
- タイムスタンプの表示ロジック
- 楽曲マッピング表示
- 共有メニュー
- 報告ボタン

#### 2-3. 配信サービスボタングループ

**新規ファイル**: `resources/views/components/distribution-buttons.blade.php`

```php
// 配列で管理
$services = [
    ['name' => 'Spotify', 'icon' => '...', 'urlMethod' => 'getSpotifyUrl'],
    ['name' => 'Apple Music', 'icon' => '...', 'urlMethod' => 'getAppleMusicUrl'],
    // ...
];
```

#### 2-4. 頭文字フィルターコンポーネント

**新規ファイル**: `resources/views/components/initial-filter.blade.php`

抽出対象:
- 数字・アルファベット・五十音・その他ボタン
- 最近使用したフィルター表示

### Phase 3: 検索ボックス統一（優先度: 低）

**変更ファイル**: `resources/views/channels/partials/search-box.blade.php`

- 共通の検索ボックスコンポーネント化
- タブ固有の機能はスロットまたはプロパティで切り替え

---

## 実装時の注意点

### 後方互換性

- URL状態管理（`restoreStateFromURL`, `updateURL`）の動作を維持
- 既存のAPIエンドポイントは変更しない

### テスト

- JavaScript分割後、各機能の動作確認を実施
- 特に自動再抽選・動画プレイヤーの連携を重点的にテスト

### 段階的リリース

- Phase 1 → Phase 2 → Phase 3 の順で段階的に実施
- 各Phase完了後にレビュー・動作確認を行う

---

## 関連ファイル一覧

### JavaScript

- `resources/js/channels/archive-list.js` (メイン、1149行)
- `resources/js/services/ChannelApiService.js` (API通信)
- `resources/js/utils/distribution-urls.js` (配信サービスURL生成)

### Bladeテンプレート

- `resources/views/channels/show.blade.php` (メインビュー)
- `resources/views/channels/partials/tabs.blade.php` (タブ・ヘッダー)
- `resources/views/channels/partials/search-box.blade.php` (検索ボックス)
- `resources/views/channels/partials/archives-tab.blade.php` (アーカイブタブ)
- `resources/views/channels/partials/timestamps-tab.blade.php` (タイムスタンプタブ)
- `resources/views/channels/partials/distribution-panel.blade.php` (配信パネル)
- `resources/views/channels/partials/video-player.blade.php` (動画プレイヤー)
- `resources/views/channels/partials/report-modal.blade.php` (報告モーダル)

### コントローラー

- `app/Http/Controllers/ChannelController.php`

---

## 作成日

2024年12月 (調査実施日に基づく)

## ステータス

**未着手** - 他の修正完了後に着手予定
