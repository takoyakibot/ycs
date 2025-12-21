# チャンネル一覧画面リファクタリング計画

## 概要

チャンネル一覧画面（アーカイブ・タイムスタンプ）は機能追加により複雑化している。
本ドキュメントは今後のリファクタリング方針を記録する。

## 現状分析

### 複雑度評価

| 項目 | 評価 | 説明 |
|------|------|------|
| JavaScript複雑度 | 5/10 | 1001行（Phase 1実施後） |
| Blade可読性 | 4/10 | 深いネストと条件分岐 |
| コード重複 | 中程度 | 特にページネーション |

### 問題のあるファイル

| ファイル | 行数 | 問題点 |
|---------|------|--------|
| `resources/js/channels/archive-list.js` | 1001 | 責務が集中（Phase 1で一部分離済み） |
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
- ~~YouTube IFrame API管理~~ → VideoPlayerManagerに分離済み
- 動画プレイヤー制御（ドラッグ可能）
- ~~自動再抽選ロジック~~ → AutoReshuffleManagerに分離済み
- ~~フェードイン・アウト制御~~ → AutoReshuffleManagerに分離済み
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

### Phase 1: JavaScript分割（優先度: 高）【一部完了】

#### 1-1. 動画プレイヤーの分離 ✅ 完了

**実装ファイル**: `resources/js/channels/managers/VideoPlayerManager.js`

抽出対象:
- YouTube IFrame API管理
- プレイヤー初期化・破棄
- 動画読み込み・再生制御
- 音量・PiPサイズ管理
- プレイヤー表示状態管理

**注記**: ドラッグ機能はAlpine.jsのリアクティブ状態との連携が複雑なため、インライン実装を維持。

#### 1-2. 自動再抽選ロジックの分離 ✅ 完了

**実装ファイル**: `resources/js/channels/managers/AutoReshuffleManager.js`

抽出対象:
- 再生位置監視
- フェードイン・アウト制御
- バッファリングタイムアウト検知
- スタック検知
- 終了時刻計算

#### 1-3. 検索・フィルター処理の整理 ⏭️ スキップ

**理由**: コード量が少なく（約25行）、Alpine.jsとの密結合によりリスクがメリットを上回るため。

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

## Phase 1 実施結果

### 実施日

2025年12月

### 成果

| 項目 | Before | After | 削減率 |
|------|--------|-------|--------|
| archive-list.js | 1384行 | 1001行 | 約28% |

### 作成されたファイル

| ファイル | 行数 | 責務 |
|---------|------|------|
| `VideoPlayerManager.js` | 469行 | YouTube動画プレイヤー管理 |
| `AutoReshuffleManager.js` | 377行 | 自動再抽選・フェード制御 |

### スキップした項目

| 項目 | 理由 |
|------|------|
| DragManager | Alpine.jsのリアクティブ状態との同期で問題発生 |
| SearchSuggestionService | コード量が少なく（約25行）、コスト対効果が低い |

### 学んだこと

- Alpine.jsコンポーネントから状態を抽出する際は、リアクティブバインディングとの連携に注意が必要
- シングルトンパターンのManagerクラスは、コールバックを通じてAlpine側に状態変更を通知する設計が有効
- 小規模な機能（ドラッグ、サジェスト等）はインライン実装の方が保守性が高い場合がある

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

- `resources/js/channels/archive-list.js` (メイン、1001行)
- `resources/js/channels/managers/VideoPlayerManager.js` (動画プレイヤー管理、469行)
- `resources/js/channels/managers/AutoReshuffleManager.js` (自動再抽選管理、377行)
- `resources/js/channels/services/ChannelApiService.js` (API通信)
- `resources/js/channels/services/ReportService.js` (報告機能)
- `resources/js/utils/music-services.js` (配信サービスURL生成)

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

## 更新日

2025年12月 (Phase 1一部実施)

## ステータス

**Phase 1一部完了** - Phase 2以降は必要に応じて実施
