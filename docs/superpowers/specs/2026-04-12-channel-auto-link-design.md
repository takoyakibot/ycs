# チャンネル単位の自動紐付け実行機能

GitHub Issue: #413

## 概要

チャンネル管理画面（設定ページ）から、特定チャンネルの未紐付けタイムスタンプに対して自動紐付けを手動実行できる機能を追加する。

## 背景・目的

- 新チャンネル追加時にそのチャンネルだけ一括処理したい需要がある
- 既存の`AutoLinkService`はグローバル実行のみでチャンネル指定ができない
- 検証用途としても、チャンネル単位で即座に実行できるUIが有用

## スコープ

### 対象

- `AutoLinkService`へのチャンネルフィルタ追加
- 非同期Job（`AutoLinkChannelJob`）の新規作成
- APIエンドポイントの追加
- 設定画面UIへのボタン追加

### 対象外

- 既存紐付けのリセット・やり直し（未紐付けのみが対象）
- 処理結果のUI上でのリアルタイム表示（ログ確認で代替）
- 定期スケジュール実行の追加

## 設計

### 1. AutoLinkService拡張

`autoLinkUnlinkedTimestamps()`と`getUnlinkedTexts()`に`?string $channelId = null`パラメータを追加する。

```php
public function autoLinkUnlinkedTimestamps(
    int $limit = 100,
    ?callable $onProgress = null,
    ?string $channelId = null
): array
```

```php
protected function getUnlinkedTexts(int $limit, ?string $channelId = null): array
```

- `$channelId`が指定された場合、`getUnlinkedTexts()`で`whereHas('archive', fn($q) => $q->where('channel_id', $channelId))`を追加
- `$channelId = null`の場合は既存動作を維持（後方互換）
- 既存の`AutoLinkSongs`コマンドや`RefreshArchives`コマンドへの影響なし

### 2. AutoLinkChannelJob（新規）

`ReapplyStripPatternsJob`と同じパターンで非同期Jobを作成する。

```php
class AutoLinkChannelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;  // Spotify API呼び出しを含むため長めに設定
    public $tries = 1;      // リトライなし

    public function __construct(public Channel $channel) {}

    public function handle(AutoLinkService $autoLinkService): void
    {
        Log::info("チャンネル自動紐付け開始: {$this->channel->handle}");

        $result = $autoLinkService->autoLinkUnlinkedTimestamps(
            limit: 10000,
            onProgress: fn($msg) => Log::info("[AutoLink:{$this->channel->handle}] {$msg}"),
            channelId: $this->channel->channel_id
        );

        Log::info("チャンネル自動紐付け完了: {$this->channel->handle}", $result);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("チャンネル自動紐付け失敗: {$this->channel->handle}", [
            'error' => $exception->getMessage(),
        ]);
    }
}
```

### 3. APIエンドポイント

`ManageSettingsApiController`に新メソッドを追加する。

- ルート: `POST /api/manage/channels/{id}/auto-link`
- ミドルウェア: `auth`, `throttle:5,1`
- アクセス制御: 既存の`ManageAccessControl`トレイトを使用（オーナーまたはスーパー管理者）

```php
public function autoLink(string $id): JsonResponse
{
    $channel = $this->getChannelOrFail($id);

    AutoLinkChannelJob::dispatch($channel);

    return response()->json([
        'message' => '自動紐付けをバックグラウンドで開始しました。',
    ]);
}
```

### 4. フロントエンドUI

`resources/views/manage/settings.blade.php`に「自動紐付け実行」セクションを追加する。

- 既存の「除去パターン再適用」ボタンと同じUIパターン
- 確認ダイアログ表示後にPOST実行
- 成功時はメッセージ表示

`resources/js/manage/settings.js`に`autoLinkChannel()`メソッドを追加する。

- `reapplyStripPatterns()`と同じパターン（confirm → POST → メッセージ表示）

### 5. ルーティング

`routes/web.php`に追加:

```php
Route::post('api/manage/channels/{id}/auto-link', [ManageSettingsApiController::class, 'autoLink'])
    ->name('manage.autoLink')
    ->middleware('throttle:5,1');
```

## テスト方針

- `AutoLinkService`のチャンネルフィルタが正しく動作することのユニットテスト
- `AutoLinkChannelJob`のテスト（Jobがdispatchされること、Serviceが正しい引数で呼ばれること）
- APIエンドポイントのFeatureテスト（認証・認可、Jobディスパッチ確認）
