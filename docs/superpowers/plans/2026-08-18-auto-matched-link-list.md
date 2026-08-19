# 自動判定の紐付け内容を確認する一覧画面 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** TS分解画面の「自動判定を一括紐付け」で何が楽曲マスタに紐付いたのかを、一覧ページで確認できるようにする。

**Architecture:** Blade によるサーバーサイドレンダリング。`TimestampDecompositionService` に一覧取得メソッドを追加し、`TimestampDecompositionController::linked()` が Blade に paginator を渡すだけの構成にする。JavaScript は書かない（Feature テストで全ての振る舞いを検証できるようにするため）。

**Tech Stack:** Laravel 10 / Blade / Tailwind CSS / PHPUnit (SQLite in-memory)

## Global Constraints

- 対象は `status = auto_matched` のレコードのみ。`pending` / `selected` / `skipped` は一覧に出さない
- 紐付け済み（`song_id` あり）と未紐付け（`song_id` なし）の両方を表示する
- 1ページ 50 件、`updated_at` の降順
- 絞り込みはクエリパラメータ `filter`。有効値は `linked` / `unlinked` / `empty_artist`。それ以外の値（未指定を含む）は「すべて」として扱う
- ルートは `routes/web.php` の `auth` + `admin` グループ内に置く
- 認可は `EnsureUserIsAdmin`。登録ユーザーは全員管理者として扱われるため、認証済みユーザー間の権限差による 403 は存在しない
- 一覧画面から紐付けの修正・解除は行わない（タイムスタンプ正規化画面で行う）
- コード整形は `./vendor/bin/pint`、テストは `php artisan test` で確認する
- 日本語のコメント・画面文言を使う（既存コードの慣行に合わせる）

---

### Task 1: Service に一覧取得メソッドを追加

**Files:**
- Modify: `app/Services/TimestampDecompositionService.php`（末尾の `bulkLinkAutoMatched()` の後に追加）
- Test: `tests/Unit/Services/AutoMatchedListTest.php`（新規）

**Interfaces:**
- Consumes: `App\Models\TimestampDecomposition`（`song()` リレーションは既に定義済み）、`App\Models\Song`
- Produces: `TimestampDecompositionService::getAutoMatchedList(?string $filter = null, int $perPage = 50): \Illuminate\Contracts\Pagination\LengthAwarePaginator`
  - 返る各要素は `TimestampDecomposition` モデルで、`song` リレーションが eager load 済み

- [ ] **Step 1: テストファイルを作成して失敗するテストを書く**

`tests/Unit/Services/AutoMatchedListTest.php` を新規作成する。

```php
<?php

namespace Tests\Unit\Services;

use App\Helpers\TextNormalizer;
use App\Models\Song;
use App\Models\TimestampDecomposition;
use App\Services\TimestampDecompositionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutoMatchedListTest extends TestCase
{
    use RefreshDatabase;

    private TimestampDecompositionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TimestampDecompositionService;
    }

    private function createDecomposition(string $text, array $attributes = []): TimestampDecomposition
    {
        return TimestampDecomposition::create(array_merge([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize($text).'-'.Str::random(6),
            'original_text' => $text,
            'parts' => ['曲名', 'アーティスト'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_AUTO_MATCHED,
            'title_part_index' => 0,
            'derived_title' => '曲名',
            'artist_part_index' => 1,
            'derived_artist' => 'アーティスト',
            'confidence' => 0.8,
        ], $attributes));
    }

    /**
     * auto_matched 以外のステータスは一覧に含まれないこと
     */
    public function test_returns_only_auto_matched(): void
    {
        $target = $this->createDecomposition('対象 / アーティスト');
        $this->createDecomposition('待ち / アーティスト', ['status' => TimestampDecomposition::STATUS_PENDING]);
        $this->createDecomposition('済み / アーティスト', ['status' => TimestampDecomposition::STATUS_SELECTED]);
        $this->createDecomposition('スキップ / アーティスト', ['status' => TimestampDecomposition::STATUS_SKIPPED]);

        $result = $this->service->getAutoMatchedList();

        $this->assertCount(1, $result);
        $this->assertEquals($target->id, $result->first()->id);
    }

    /**
     * 紐付け済み・未紐付けの両方が含まれること
     */
    public function test_includes_both_linked_and_unlinked(): void
    {
        $song = Song::factory()->create();
        $this->createDecomposition('紐付け済み / アーティスト', ['song_id' => $song->id]);
        $this->createDecomposition('未紐付け / アーティスト');

        $this->assertCount(2, $this->service->getAutoMatchedList());
    }

    /**
     * filter=linked で紐付け済みだけに絞られること
     */
    public function test_filter_linked(): void
    {
        $song = Song::factory()->create();
        $linked = $this->createDecomposition('紐付け済み / アーティスト', ['song_id' => $song->id]);
        $this->createDecomposition('未紐付け / アーティスト');

        $result = $this->service->getAutoMatchedList('linked');

        $this->assertCount(1, $result);
        $this->assertEquals($linked->id, $result->first()->id);
    }

    /**
     * filter=unlinked で未紐付けだけに絞られること
     */
    public function test_filter_unlinked(): void
    {
        $song = Song::factory()->create();
        $this->createDecomposition('紐付け済み / アーティスト', ['song_id' => $song->id]);
        $unlinked = $this->createDecomposition('未紐付け / アーティスト');

        $result = $this->service->getAutoMatchedList('unlinked');

        $this->assertCount(1, $result);
        $this->assertEquals($unlinked->id, $result->first()->id);
    }

    /**
     * filter=empty_artist は、紐付け済みなら songs.artist、未紐付けなら derived_artist を見ること
     */
    public function test_filter_empty_artist_checks_both_sources(): void
    {
        $emptyArtistSong = Song::factory()->create(['artist' => '']);
        $filledArtistSong = Song::factory()->create(['artist' => 'アーティスト']);

        $linkedEmpty = $this->createDecomposition('紐付け済み・空 / x', ['song_id' => $emptyArtistSong->id]);
        $unlinkedEmpty = $this->createDecomposition('未紐付け・空', [
            'parts' => ['曲名'],
            'separator_count' => 0,
            'artist_part_index' => null,
            'derived_artist' => null,
        ]);
        $this->createDecomposition('紐付け済み・あり / x', ['song_id' => $filledArtistSong->id]);
        $this->createDecomposition('未紐付け・あり / アーティスト');

        $result = $this->service->getAutoMatchedList('empty_artist');

        $this->assertCount(2, $result);
        $ids = $result->pluck('id')->all();
        $this->assertContains($linkedEmpty->id, $ids);
        $this->assertContains($unlinkedEmpty->id, $ids);
    }

    /**
     * 不正な filter 値は「すべて」として扱われること
     */
    public function test_invalid_filter_returns_all(): void
    {
        $song = Song::factory()->create();
        $this->createDecomposition('紐付け済み / アーティスト', ['song_id' => $song->id]);
        $this->createDecomposition('未紐付け / アーティスト');

        $this->assertCount(2, $this->service->getAutoMatchedList('nonsense'));
    }

    /**
     * updated_at の降順で並ぶこと
     */
    public function test_orders_by_updated_at_desc(): void
    {
        $old = $this->createDecomposition('古い / アーティスト');
        $new = $this->createDecomposition('新しい / アーティスト');

        $old->timestamps = false;
        $old->updated_at = now()->subDay();
        $old->save();

        $result = $this->service->getAutoMatchedList();

        $this->assertEquals($new->id, $result->first()->id);
    }

    /**
     * ページングされること
     */
    public function test_paginates(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->createDecomposition("曲{$i} / アーティスト");
        }

        $result = $this->service->getAutoMatchedList(null, 2);

        $this->assertCount(2, $result);
        $this->assertEquals(3, $result->total());
        $this->assertEquals(2, $result->lastPage());
    }

    /**
     * song リレーションが eager load されていること（N+1 回避）
     */
    public function test_eager_loads_song(): void
    {
        $song = Song::factory()->create();
        $this->createDecomposition('紐付け済み / アーティスト', ['song_id' => $song->id]);

        $result = $this->service->getAutoMatchedList();

        $this->assertTrue($result->first()->relationLoaded('song'));
    }
}
```

- [ ] **Step 2: テストを実行して失敗を確認**

Run: `php artisan test --filter=AutoMatchedListTest`
Expected: FAIL（`Call to undefined method App\Services\TimestampDecompositionService::getAutoMatchedList()`）

- [ ] **Step 3: Service にメソッドを実装**

`app/Services/TimestampDecompositionService.php` の `bulkLinkAutoMatched()` メソッドの直後（クラスの閉じ括弧の直前）に追加する。

```php
    /**
     * 自動判定されたアイテムの一覧を取得
     *
     * 紐付け済み（song_id あり）と未紐付けの両方を返す。
     * TS分解画面の統計「自動: N件」と件数を一致させるため。
     *
     * @param  string|null  $filter  'linked' | 'unlinked' | 'empty_artist' | それ以外は絞り込みなし
     */
    public function getAutoMatchedList(?string $filter = null, int $perPage = 50): LengthAwarePaginator
    {
        $query = TimestampDecomposition::with('song')
            ->where('status', TimestampDecomposition::STATUS_AUTO_MATCHED)
            ->orderByDesc('updated_at');

        if ($filter === 'linked') {
            $query->whereNotNull('song_id');
        } elseif ($filter === 'unlinked') {
            $query->whereNull('song_id');
        } elseif ($filter === 'empty_artist') {
            // 紐付け済みなら楽曲マスタのアーティスト名、未紐付けなら判定結果を見る
            $query->where(function ($outer) {
                $outer->where(function ($q) {
                    $q->whereNull('song_id')
                        ->where(function ($inner) {
                            $inner->whereNull('derived_artist')->orWhere('derived_artist', '');
                        });
                })->orWhere(function ($q) {
                    $q->whereNotNull('song_id')
                        ->whereHas('song', function ($songQuery) {
                            $songQuery->whereNull('artist')->orWhere('artist', '');
                        });
                });
            });
        }

        return $query->paginate($perPage);
    }
```

ファイル冒頭の `use` 宣言に以下を追加する（既存の `use` 群のアルファベット順の位置に入れる。Pint が並び順を整えるので、追加後に `./vendor/bin/pint` を実行すればよい）。

```php
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
```

- [ ] **Step 4: テストを実行して成功を確認**

Run: `php artisan test --filter=AutoMatchedListTest`
Expected: PASS（9 tests）

- [ ] **Step 5: 整形して全テストを実行**

Run: `./vendor/bin/pint && php artisan test`
Expected: Pint が PASS、全テスト PASS

- [ ] **Step 6: コミット**

```bash
git add app/Services/TimestampDecompositionService.php tests/Unit/Services/AutoMatchedListTest.php
git commit -m "feat: 自動判定されたアイテムの一覧を取得するメソッドを追加"
```

---

### Task 2: 一覧ページ（ルート・Controller・Blade）

**Files:**
- Modify: `routes/web.php`（`/songs/decompose` の定義の直後）
- Modify: `app/Http/Controllers/TimestampDecompositionController.php`（`index()` の直後）
- Create: `resources/views/songs/decompose-linked.blade.php`
- Test: `tests/Feature/AutoMatchedLinkListTest.php`（新規）

**Interfaces:**
- Consumes: `TimestampDecompositionService::getAutoMatchedList(?string $filter = null, int $perPage = 50): LengthAwarePaginator`（Task 1）
- Produces: 名前付きルート `songs.decompose.linked`（パス `/songs/decompose/linked`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/AutoMatchedLinkListTest.php` を新規作成する。

```php
<?php

namespace Tests\Feature;

use App\Helpers\TextNormalizer;
use App\Models\Song;
use App\Models\TimestampDecomposition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 自動判定の紐付け内容を確認する一覧ページ
 */
class AutoMatchedLinkListTest extends TestCase
{
    use RefreshDatabase;

    private function createDecomposition(string $text, array $attributes = []): TimestampDecomposition
    {
        return TimestampDecomposition::create(array_merge([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize($text).'-'.Str::random(6),
            'original_text' => $text,
            'parts' => ['曲名', 'アーティスト'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_AUTO_MATCHED,
            'title_part_index' => 0,
            'derived_title' => '曲名',
            'artist_part_index' => 1,
            'derived_artist' => 'アーティスト',
            'confidence' => 0.8,
        ], $attributes));
    }

    /**
     * 未認証ならログイン画面にリダイレクトされること
     */
    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('songs.decompose.linked'))->assertRedirect('/login');
    }

    /**
     * 紐付け済みは楽曲マスタの値が表示されること
     *
     * 元テキストと楽曲マスタの値をあえて別の文字列にして、
     * 元テキストの表示で偶然通ってしまわないようにする
     */
    public function test_shows_song_master_values_for_linked(): void
    {
        $this->actingAs(User::factory()->create());

        $song = Song::factory()->create([
            'title' => 'マスタの曲名',
            'artist' => 'マスタのアーティスト',
        ]);
        $this->createDecomposition('紐付け済みの元テキスト', ['song_id' => $song->id]);

        $response = $this->get(route('songs.decompose.linked'));

        $response->assertOk();
        $response->assertSee('紐付け済みの元テキスト');
        $response->assertSee('マスタの曲名');
        $response->assertSee('マスタのアーティスト');
        $response->assertSee('紐付け済み');
    }

    /**
     * 未紐付けは判定結果の値が表示されること
     *
     * 元テキストと判定結果をあえて別の文字列にして、
     * 元テキストの表示で偶然通ってしまわないようにする
     */
    public function test_shows_derived_values_for_unlinked(): void
    {
        $this->actingAs(User::factory()->create());

        $this->createDecomposition('未紐付けの元テキスト', [
            'derived_title' => '判定された曲名',
            'derived_artist' => '判定されたアーティスト',
        ]);

        $response = $this->get(route('songs.decompose.linked'));

        $response->assertOk();
        $response->assertSee('未紐付けの元テキスト');
        $response->assertSee('判定された曲名');
        $response->assertSee('判定されたアーティスト');
        $response->assertSee('未紐付け');
    }

    /**
     * auto_matched 以外は表示されないこと
     */
    public function test_does_not_show_other_statuses(): void
    {
        $this->actingAs(User::factory()->create());

        $this->createDecomposition('処理待ちのテキスト / アーティスト', [
            'status' => TimestampDecomposition::STATUS_PENDING,
        ]);

        $this->get(route('songs.decompose.linked'))
            ->assertOk()
            ->assertDontSee('処理待ちのテキスト');
    }

    /**
     * filter=linked が効くこと
     */
    public function test_filter_linked(): void
    {
        $this->actingAs(User::factory()->create());

        $song = Song::factory()->create();
        $this->createDecomposition('紐付け済みのテキスト / アーティスト', ['song_id' => $song->id]);
        $this->createDecomposition('未紐付けのテキスト / アーティスト');

        $this->get(route('songs.decompose.linked', ['filter' => 'linked']))
            ->assertOk()
            ->assertSee('紐付け済みのテキスト')
            ->assertDontSee('未紐付けのテキスト');
    }

    /**
     * filter=unlinked が効くこと
     */
    public function test_filter_unlinked(): void
    {
        $this->actingAs(User::factory()->create());

        $song = Song::factory()->create();
        $this->createDecomposition('紐付け済みのテキスト / アーティスト', ['song_id' => $song->id]);
        $this->createDecomposition('未紐付けのテキスト / アーティスト');

        $this->get(route('songs.decompose.linked', ['filter' => 'unlinked']))
            ->assertOk()
            ->assertSee('未紐付けのテキスト')
            ->assertDontSee('紐付け済みのテキスト');
    }

    /**
     * filter=empty_artist が効くこと
     */
    public function test_filter_empty_artist(): void
    {
        $this->actingAs(User::factory()->create());

        $emptyArtistSong = Song::factory()->create(['artist' => '']);
        $this->createDecomposition('空アーティストのテキスト / x', ['song_id' => $emptyArtistSong->id]);
        $this->createDecomposition('アーティストありのテキスト / アーティスト');

        $this->get(route('songs.decompose.linked', ['filter' => 'empty_artist']))
            ->assertOk()
            ->assertSee('空アーティストのテキスト')
            ->assertDontSee('アーティストありのテキスト');
    }

    /**
     * 不正な filter 値はすべて表示として扱われること
     */
    public function test_invalid_filter_shows_all(): void
    {
        $this->actingAs(User::factory()->create());

        $song = Song::factory()->create();
        $this->createDecomposition('紐付け済みのテキスト / アーティスト', ['song_id' => $song->id]);
        $this->createDecomposition('未紐付けのテキスト / アーティスト');

        $this->get(route('songs.decompose.linked', ['filter' => 'nonsense']))
            ->assertOk()
            ->assertSee('紐付け済みのテキスト')
            ->assertSee('未紐付けのテキスト');
    }

    /**
     * アーティスト名が空の行に警告が表示されること
     *
     * 絞り込みボタンのラベル「⚠ アーティスト名が空」は常に表示されるため、
     * それとは別の文言「⚠ 未設定」を行内の警告として検証する
     */
    public function test_warns_when_artist_is_empty(): void
    {
        $this->actingAs(User::factory()->create());

        $emptyArtistSong = Song::factory()->create(['artist' => '']);
        $this->createDecomposition('空アーティストのテキスト / x', ['song_id' => $emptyArtistSong->id]);

        $this->get(route('songs.decompose.linked'))
            ->assertOk()
            ->assertSee('⚠ 未設定');
    }

    /**
     * アーティスト名が入っている行には警告が出ないこと
     */
    public function test_does_not_warn_when_artist_is_present(): void
    {
        $this->actingAs(User::factory()->create());

        $song = Song::factory()->create(['artist' => 'アーティスト']);
        $this->createDecomposition('アーティストありのテキスト / x', ['song_id' => $song->id]);

        $this->get(route('songs.decompose.linked'))
            ->assertOk()
            ->assertDontSee('⚠ 未設定');
    }

    /**
     * 51件で2ページになること
     */
    public function test_paginates_at_50_per_page(): void
    {
        $this->actingAs(User::factory()->create());

        for ($i = 0; $i < 51; $i++) {
            $this->createDecomposition("曲{$i} / アーティスト");
        }

        $response = $this->get(route('songs.decompose.linked'));

        $response->assertOk();
        $response->assertViewHas('decompositions', function ($paginator) {
            return $paginator->total() === 51
                && $paginator->count() === 50
                && $paginator->lastPage() === 2;
        });
    }

    /**
     * ページ送りしても絞り込みが保持されること
     */
    public function test_keeps_filter_across_pages(): void
    {
        $this->actingAs(User::factory()->create());

        for ($i = 0; $i < 51; $i++) {
            $this->createDecomposition("曲{$i} / アーティスト");
        }

        $this->get(route('songs.decompose.linked', ['filter' => 'unlinked']))
            ->assertOk()
            ->assertSee('filter=unlinked', false);
    }

    /**
     * TS分解画面に一覧ページへのリンクがあること
     */
    public function test_decompose_page_links_to_list(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('songs.decompose'))
            ->assertOk()
            ->assertSee(route('songs.decompose.linked'), false);
    }
}
```

- [ ] **Step 2: テストを実行して失敗を確認**

Run: `php artisan test --filter=AutoMatchedLinkListTest`
Expected: FAIL（`Route [songs.decompose.linked] not defined.`）

- [ ] **Step 3: ルートを追加**

`routes/web.php` の `Route::get('/songs/decompose', ...)` の直後に追加する。

```php
    Route::get('/songs/decompose/linked', [TimestampDecompositionController::class, 'linked'])->name('songs.decompose.linked');
```

- [ ] **Step 4: Controller にアクションを追加**

`app/Http/Controllers/TimestampDecompositionController.php` の `index()` メソッドの直後に追加する。

```php
    /**
     * 自動判定されたアイテムの一覧を表示
     *
     * 一括紐付けで何が紐付いたのかを確認するための画面。
     * 修正はタイムスタンプ正規化画面で行うため、この画面は参照のみ。
     */
    public function linked(Request $request): View
    {
        $filter = $request->query('filter');

        return view('songs.decompose-linked', [
            'decompositions' => $this->service->getAutoMatchedList(is_string($filter) ? $filter : null),
            'filter' => $filter,
        ]);
    }
```

`Request` と `View` は既に use 済みなので追加不要。

- [ ] **Step 5: Blade を作成**

`resources/views/songs/decompose-linked.blade.php` を新規作成する。

```blade
@php
    $filters = [
        '' => 'すべて',
        'linked' => '紐付け済み',
        'unlinked' => '未紐付け',
        'empty_artist' => '⚠ アーティスト名が空',
    ];
@endphp

<x-app-layout>
    <div class="py-4">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-4">
                <div class="p-4 text-gray-900 dark:text-gray-100">
                    <div class="flex items-center justify-between flex-wrap gap-2">
                        <div class="flex items-center gap-4">
                            <h3 class="text-lg font-semibold">自動判定の紐付け内容</h3>
                            <span class="text-sm text-gray-500 dark:text-gray-400">
                                {{ number_format($decompositions->total()) }}件
                            </span>
                        </div>
                        <a href="{{ route('songs.decompose') }}"
                           class="px-3 py-1 bg-gray-500 text-white text-sm rounded hover:bg-gray-600">
                            分解・選別に戻る
                        </a>
                    </div>

                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($filters as $value => $label)
                            @php $active = (string) $filter === (string) $value; @endphp
                            <a href="{{ route('songs.decompose.linked', $value === '' ? [] : ['filter' => $value]) }}"
                               class="px-3 py-1 text-sm rounded border {{ $active
                                   ? 'bg-purple-600 text-white border-purple-600'
                                   : 'border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700' }}">
                                {{ $label }}
                            </a>
                        @endforeach
                    </div>

                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                        紐付けが誤っている場合はタイムスタンプ正規化画面で修正してください。
                    </p>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-4 text-gray-900 dark:text-gray-100">
                    @if ($decompositions->isEmpty())
                        <p class="text-sm text-gray-500 dark:text-gray-400 py-4">
                            該当するアイテムがありません。
                        </p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="text-left text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                                        <th class="py-2 pr-4 font-medium">元テキスト</th>
                                        <th class="py-2 pr-4 font-medium">曲名 / アーティスト</th>
                                        <th class="py-2 pr-4 font-medium whitespace-nowrap">確信度</th>
                                        <th class="py-2 pr-4 font-medium whitespace-nowrap">状態</th>
                                        <th class="py-2 font-medium whitespace-nowrap">日時</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($decompositions as $decomposition)
                                        @php
                                            // 紐付け済みなら楽曲マスタの値、未紐付けなら判定結果を表示する
                                            $title = $decomposition->song ? $decomposition->song->title : $decomposition->derived_title;
                                            $artist = $decomposition->song ? $decomposition->song->artist : $decomposition->derived_artist;
                                            $artistIsEmpty = $artist === null || trim($artist) === '';
                                        @endphp
                                        <tr class="border-b border-gray-100 dark:border-gray-700 {{ $artistIsEmpty ? 'bg-amber-50 dark:bg-amber-900/20' : '' }}">
                                            <td class="py-2 pr-4 break-all">{{ $decomposition->original_text }}</td>
                                            <td class="py-2 pr-4 break-all">
                                                <span class="text-blue-600 dark:text-blue-400">{{ $title }}</span>
                                                <span class="text-gray-400">/</span>
                                                @if ($artistIsEmpty)
                                                    <span class="text-amber-700 dark:text-amber-400 font-medium">⚠ 未設定</span>
                                                @else
                                                    <span class="text-green-600 dark:text-green-400">{{ $artist }}</span>
                                                @endif
                                            </td>
                                            <td class="py-2 pr-4 whitespace-nowrap">
                                                {{ $decomposition->confidence === null ? '-' : round($decomposition->confidence * 100).'%' }}
                                            </td>
                                            <td class="py-2 pr-4 whitespace-nowrap">
                                                @if ($decomposition->song_id)
                                                    <span class="text-green-600 dark:text-green-400">紐付け済み</span>
                                                @else
                                                    <span class="text-gray-500 dark:text-gray-400">未紐付け</span>
                                                @endif
                                            </td>
                                            <td class="py-2 whitespace-nowrap text-gray-500 dark:text-gray-400">
                                                {{ $decomposition->updated_at?->format('Y-m-d H:i') }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-4">
                            {{ $decompositions->withQueryString()->links() }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
```

- [ ] **Step 6: TS分解画面の「自動: N件」をリンクにする**

`resources/views/songs/decompose.blade.php` の統計表示部分を書き換える。変更前:

```blade
                                <span class="text-gray-500 dark:text-gray-400">
                                    自動: <span id="statAutoMatched" class="font-medium text-blue-600">-</span>件
                                </span>
```

変更後（`id="statAutoMatched"` の span はそのまま残す。JS がこの要素の `textContent` だけを書き換えるため、外側をリンクで包んでも影響しない）:

```blade
                                <a href="{{ route('songs.decompose.linked') }}"
                                   class="text-gray-500 dark:text-gray-400 hover:underline"
                                   title="自動判定の紐付け内容を確認する">
                                    自動: <span id="statAutoMatched" class="font-medium text-blue-600">-</span>件
                                </a>
```

- [ ] **Step 7: テストを実行して成功を確認**

Run: `php artisan test --filter=AutoMatchedLinkListTest`
Expected: PASS（13 tests）

- [ ] **Step 8: 整形・全テスト・ビルドを確認**

Run: `./vendor/bin/pint && php artisan test && npm run build`
Expected: Pint が PASS、全テスト PASS、ビルド成功

新規の JS ファイルは追加していないため `vite.config.js` の変更は不要。

- [ ] **Step 9: コミット**

```bash
git add routes/web.php app/Http/Controllers/TimestampDecompositionController.php resources/views/songs/decompose-linked.blade.php resources/views/songs/decompose.blade.php tests/Feature/AutoMatchedLinkListTest.php
git commit -m "feat: 自動判定の紐付け内容を確認する一覧ページを追加"
```

---

### Task 3: 元テキストのコピーボタン

**Files:**
- Modify: `resources/views/songs/decompose-linked.blade.php`
- Test: `tests/Feature/AutoMatchedLinkListTest.php`（既存に追記）

**Interfaces:**
- Consumes: Task 2 で作成した Blade とテストクラス
- Produces: なし（画面内で完結する）

正規化画面は検索文字列を URL で受け取れないため、元テキストをコピーして正規化画面の検索欄に貼れるようにする。

- [ ] **Step 1: 失敗するテストを追記**

`tests/Feature/AutoMatchedLinkListTest.php` の `test_decompose_page_links_to_list()` の直前に追加する。

```php
    /**
     * 元テキストをコピーするボタンがあること
     */
    public function test_has_copy_button_for_original_text(): void
    {
        $this->actingAs(User::factory()->create());

        $this->createDecomposition('コピー対象のテキスト / アーティスト');

        $this->get(route('songs.decompose.linked'))
            ->assertOk()
            ->assertSee('data-copy-text="コピー対象のテキスト / アーティスト"', false);
    }
```

- [ ] **Step 2: テストを実行して失敗を確認**

Run: `php artisan test --filter=test_has_copy_button_for_original_text`
Expected: FAIL（`data-copy-text` が見つからない）

- [ ] **Step 3: Blade にコピーボタンを追加**

`resources/views/songs/decompose-linked.blade.php` の元テキストのセルを書き換える。変更前:

```blade
                                            <td class="py-2 pr-4 break-all">{{ $decomposition->original_text }}</td>
```

変更後:

```blade
                                            <td class="py-2 pr-4 break-all">
                                                <div class="flex items-start gap-2">
                                                    <span>{{ $decomposition->original_text }}</span>
                                                    <button type="button"
                                                            data-copy-text="{{ $decomposition->original_text }}"
                                                            class="shrink-0 px-2 py-0.5 text-xs border border-gray-300 dark:border-gray-600 rounded text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700"
                                                            title="元テキストをコピー（正規化画面の検索に貼り付けられます）">
                                                        コピー
                                                    </button>
                                                </div>
                                            </td>
```

- [ ] **Step 4: コピー動作のスクリプトを追加**

同じ Blade ファイルの末尾、`</x-app-layout>` の直前に追加する。この画面専用の数行なのでインラインに置く（新規 JS ファイルを作ると `vite.config.js` への登録が必要になり、この規模には見合わない）。

```blade
    <script>
        document.addEventListener('click', (e) => {
            const button = e.target.closest('[data-copy-text]');
            if (!button) return;

            navigator.clipboard.writeText(button.dataset.copyText).then(() => {
                const original = button.textContent;
                button.textContent = 'コピーしました';
                setTimeout(() => { button.textContent = original; }, 1500);
            });
        });
    </script>
```

- [ ] **Step 5: テストを実行して成功を確認**

Run: `php artisan test --filter=AutoMatchedLinkListTest`
Expected: PASS（14 tests）

- [ ] **Step 6: 整形・全テストを確認**

Run: `./vendor/bin/pint && php artisan test`
Expected: Pint が PASS、全テスト PASS

- [ ] **Step 7: コミット**

```bash
git add resources/views/songs/decompose-linked.blade.php tests/Feature/AutoMatchedLinkListTest.php
git commit -m "feat: 一覧の元テキストにコピーボタンを追加"
```

---

## 完了後の確認

- [ ] `php artisan test` が全て PASS
- [ ] `./vendor/bin/pint --test` が PASS
- [ ] `npm run build` が成功
- [ ] 実際の画面で以下を確認
  - TS分解画面の「自動: N件」をクリックして一覧が開くこと
  - 絞り込み 4 種がそれぞれ機能すること
  - アーティスト名が空の行が警告表示されること
  - コピーボタンでクリップボードにコピーされること
  - ページ送りで絞り込みが保持されること
  - ダークモードで表示が崩れないこと
