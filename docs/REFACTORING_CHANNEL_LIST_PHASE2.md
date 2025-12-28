# チャンネル一覧画面リファクタリング計画 Phase 2

## 概要

Phase 1（VideoPlayerManager、AutoReshuffleManager抽出）完了後の追加リファクタリング。
JavaScript側とBlade側の両方をバランスよく進める。

## 現状

- `archive-list.js`: 1,001行（Phase 1で1,384行から28%削減済み）
- `timestamps-tab.blade.php`: 311行（ページネーション重複あり）
- `distribution-panel.blade.php`: 177行（配信サービスボタン手動配置）

## 実装方針

- **段階的実装**: 各PRを分けて段階的にマージ
- **低リスク優先**: 簡単で効果の高いものから着手
- **DragManagerスキップ**: 前回問題が発生したためインライン実装を維持

## PR構成

| PR | 内容 | 領域 | 工数 |
|----|------|------|------|
| PR 1 | 楽曲選択ロジック重複解消 | JavaScript | 1h |
| PR 2 | 配信サービスURLメソッド整理 | JavaScript | 30m |
| PR 3 | ページネーションコンポーネント化 | Blade | 2h |
| PR 4 | 配信サービスボタンのループ化 | Blade | 1h |
| PR 5 | ドキュメント更新 | Docs | 30m |

---

## PR 1: 楽曲選択ロジック重複解消

### ブランチ: `refactor/song-selection-logic`

### 問題点

`selectSong`と`selectText`に重複コードがある：
1. 自動再抽選の中断処理（5行重複）
2. 楽曲選択後の共通処理（8行重複）

### 対象ファイル

- **修正**: `resources/js/channels/archive-list.js`

### 実装内容

```javascript
// 新規メソッド
_disableAutoReshuffleIfActive() {
    if (this.autoReshuffle) {
        this.autoReshuffle = false;
        autoReshuffleManager.setEnabled(false);
        toast.info('自動再抽選をOFFにしました');
    }
}

_handleSongSelection(song, timestamp) {
    this.selectedSong = song;
    this.selectedTimestamp = timestamp;
    if (!this.panelDismissed) {
        this.showDistributionPanel = true;
    }
    if (this.autoPlay && timestamp?.video_id) {
        this.loadAndPlayVideo(timestamp.video_id, timestamp.ts_num || 0);
    }
}

// selectSong/selectText を簡略化
selectSong(song, timestamp) {
    this._disableAutoReshuffleIfActive();
    savePlayHistory(this.channel.handle, song, timestamp);
    this._handleSongSelection(song, timestamp);
    logUserAction('selectSong', { ... });
}
```

### 期待効果

- 約15行の重複解消
- 保守性向上（変更箇所の一元化）

---

## PR 2: 配信サービスURLメソッド整理

### ブランチ: `refactor/music-service-urls`

### 問題点

`archive-list.js`に配信サービスURLメソッドがラッパーとして存在するが、
`music-services.js`に既に本体が存在。不要な中間層。

### 対象ファイル

- **修正**: `resources/js/channels/archive-list.js`
- **修正**: `resources/views/channels/partials/distribution-panel.blade.php`

### 実装内容

**archive-list.js から削除:**
```javascript
// これらを削除（約20行）
getSpotifyUrl(song) { ... }
getAppleMusicUrl(song) { ... }
getYouTubeMusicUrl(song) { ... }
getAmazonMusicUrl(song) { ... }
getLineMusicUrl(song) { ... }
```

**Blade側で直接import関数を使用:**
```blade
{{-- Alpine.jsのメソッドではなく、グローバル関数として使用 --}}
<a :href="window.getSpotifyUrl(selectedSong)" ...>
```

または、Alpine.jsコンポーネント初期化時にインポート関数をバインド。

### 期待効果

- 約20行削減
- 単一責任原則の徹底

---

## PR 3: ページネーションコンポーネント化

### ブランチ: `refactor/pagination-component`

### 問題点

`timestamps-tab.blade.php`に上下で同じページネーションコードが87%重複。

### 対象ファイル

- **修正**: `resources/views/components/pagination.blade.php`
- **修正**: `resources/views/channels/partials/timestamps-tab.blade.php`

### 実装内容

**新規コンポーネント props:**
```php
@props([
    'currentPage' => 1,
    'lastPage' => 1,
    'onFirst' => null,
    'onPrev' => null,
    'onNext' => null,
    'onLast' => null,
    'scrollToTop' => false,  // 下部ページネーション用
])
```

**timestamps-tab.blade.php:**
```blade
{{-- 上部 --}}
<x-pagination
    :current-page="timestamps.current_page"
    :last-page="timestamps.last_page"
    x-on:first="fetchTimestamps(1, searchQuery, selectedIndex)"
    x-on:prev="fetchTimestamps(timestamps.current_page - 1, ...)"
    ...
/>

{{-- 下部（スクロール付き） --}}
<x-pagination
    ...
    :scroll-to-top="true"
/>
```

### 期待効果

- timestamps-tab.blade.php: 約50行削減
- 重複コード解消
- 他ページでの再利用可能

---

## PR 4: 配信サービスボタンのループ化

### ブランチ: `refactor/distribution-buttons-loop`

### 問題点

`distribution-panel.blade.php`に6つの配信サービスボタンが手動で配置されている。

### 対象ファイル

- **修正**: `resources/views/channels/partials/distribution-panel.blade.php`
- **新規**: `resources/js/channels/utils/distribution-services.js`

### 実装内容

**distribution-services.js:**
```javascript
export const DISTRIBUTION_SERVICES = [
    {
        id: 'spotify',
        name: 'Spotify',
        color: 'bg-green-600 hover:bg-green-700',
        getUrl: (song) => getSpotifyUrl(song),
        icon: '...' // SVG path
    },
    {
        id: 'apple_music',
        name: 'Apple',
        color: 'bg-pink-600 hover:bg-pink-700',
        getUrl: (song) => getAppleMusicUrl(song),
        icon: '...'
    },
    // ... 他4サービス
];
```

**distribution-panel.blade.php:**
```blade
<template x-for="service in distributionServices" :key="service.id">
    <a :href="service.getUrl(selectedSong)"
       :class="service.color"
       @click="logDistributionLinkClick(service.id)"
       target="_blank"
       class="...">
        <span x-html="service.icon"></span>
        <span x-text="service.name"></span>
    </a>
</template>
```

### 期待効果

- 約60行削減
- 新サービス追加が容易
- スタイル管理の一元化

---

## PR 5: ドキュメント更新

### ブランチ: `docs/update-refactoring-phase2`

### 対象ファイル

- **修正**: `docs/REFACTORING_CHANNEL_LIST.md`

### 更新内容

- Phase 2の実施結果を追記
- 最終的なコード行数を更新
- ステータスを「Phase 2完了」に変更

---

## 期待される成果

### コード削減

| ファイル | Before | After | 削減 |
|---------|--------|-------|------|
| archive-list.js | 1,001行 | ~965行 | ~35行 |
| timestamps-tab.blade.php | 311行 | ~260行 | ~50行 |
| distribution-panel.blade.php | 177行 | ~120行 | ~57行 |

### 品質向上

- 重複コード解消
- コンポーネント再利用性向上
- 保守性向上（変更箇所の一元化）

---

## 注意事項

- 各PRは独立して実装可能（依存関係なし）
- Alpine.jsとの連携パターンを維持
- 手動テストで動作確認必須

## スキップ項目

- **DragManager**: Alpine.jsとの状態同期で問題が発生したためスキップ
- **SearchSuggestionService**: コード量が少なく（約25行）コスト対効果が低いためスキップ
- **頭文字フィルターコンポーネント化**: Alpine.js連携が複雑なため今回はスキップ

---

## 作成日

2025年12月

## ステータス

**計画策定済み** - 実装待ち
