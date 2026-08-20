# 選択したタイムスタンプから楽曲マスタの候補を提示するタブ Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** タイムスタンプを1件選ぶと楽曲マスタの候補が出て、元テキストを分割したチップで絞り込める「候補」タブを正規化画面に追加する。

**Architecture:** 2段構成。前半は未マージのあいまい検索ブランチ（`QueryHelper::splitFuzzyKeywords()`）の不具合を直して develop に取り込む。後半はその検索処理を土台に候補タブを載せる。テキストの分割・ノイズ判定は既存の `TextNormalizer` に任せ、JS 側に同じロジックを持たせない。

**Tech Stack:** Laravel 10 / Blade / Tailwind CSS / 素の JavaScript（フレームワークなし）/ PHPUnit (SQLite in-memory)

## Global Constraints

- テキストの分割は `TextNormalizer::splitBySeparators()`、ノイズ判定は `TextNormalizer::isIgnorablePart()` を使う。同じ判定を JS 側に実装しない
- 候補の検索は `QueryHelper::applyFuzzySearch()` を使う。`normalized_title` / `normalized_artist` を対象にする
- 新規の JS ファイルは作らない（`vite.config.js` の変更も不要）。既存の `resources/js/songs/normalize.js` に追記する
- 紐付け処理（`linkTimestamps()`）には手を入れない。候補の選択は既存の `this.selectedSong` に入れるだけ
- コード整形は `./vendor/bin/pint`、テストは `php artisan test` で確認する
- 日本語のコメント・画面文言を使う（既存コードの慣行）
- Phase 1 は `feature/song-fuzzy-search` ブランチ、Phase 2 は `feature/song-candidate-tab` ブランチで作業する。Phase 1 を develop にマージした後、Phase 2 を develop に追従させる

---

## Phase 1: あいまい検索の取り込みと修正

### Task 1: あいまい検索ブランチを取り込み、競合を解決する

**Files:**
- Modify: `resources/js/songs/normalize.js`（マージ競合の解決）
- 取り込まれるファイル（競合なし）: `app/Helpers/QueryHelper.php`, `app/Http/Controllers/SongController.php`, `resources/js/songs/services/SongApiService.js`, `resources/js/songs/utils/constants.js`, `resources/views/songs/index.blade.php`, `tests/Feature/SongControllerTest.php`, `tests/Unit/Helpers/QueryHelperTest.php`

**Interfaces:**
- Produces: `QueryHelper::splitFuzzyKeywords(string $search): array` / `QueryHelper::applyFuzzySearch(Builder $query, string $search, array $columns): Builder` / `SongController::SEARCH_MODE_FUZZY` = `'fuzzy'` / `SongController::SEARCH_MODE_EXACT` = `'exact'` / `/api/songs` の `search_mode` パラメータ

- [ ] **Step 1: develop から作業ブランチを作る**

```bash
git checkout develop
git pull --ff-only origin develop
git checkout -b feature/song-fuzzy-search
```

- [ ] **Step 2: あいまい検索ブランチをマージする（競合が出る）**

```bash
git merge --no-commit origin/claude/timestamp-search-delimiter-noise-w8i3pt
```

`resources/js/songs/normalize.js` で競合します。他7ファイルは競合しません。

- [ ] **Step 3: 競合を解決する**

競合は2箇所です。どちらも「両方の変更を残す」だけで解決します。

**1箇所目: コンストラクタのプロパティ追加**

develop 側が `songsRequestSeq` / `songsQueryActive` を追加し、ブランチ側が `songSearchMode` を追加しています。両方残してください。解決後はこうなります（前後の行はファイルの内容に合わせてください）。

```javascript
        this.songsRequestSeq = 0; // 楽曲マスタ検索の発行順（古い応答の反映を防ぐ）
        this.songsQueryActive = false; // 直近の loadSongs が絞り込み条件ありと判断したか
        // 楽曲マスタの検索方式（fuzzy: 区切り文字を無視した単語検索 / exact: 入力そのままで検索）
        this.songSearchMode = CONSTANTS.SONG_SEARCH_MODE_FUZZY;
```

**2箇所目: メソッドの隣接**

develop 側の `hasSongsQuery()` と、ブランチ側の `setSongSearchMode()` / `updateSongSearchModeButtons()` が隣り合っているために競合表示されます。両メソッドをそのまま並べて残してください。

`loadSongs()` の中（`songApiService.fetchSongs(...)` の呼び出し）は競合せず自動マージされ、`await songApiService.fetchSongs(search, this.songReviewStatus, this.songSearchMode);` の形になります。この行はそのままにしてください。

- [ ] **Step 4: 競合が解決できたか確認する**

```bash
grep -n '<<<<<<<\|=======\|>>>>>>>' resources/js/songs/normalize.js
```

Expected: 何も出力されない

- [ ] **Step 5: テストとビルドを通す**

Run: `./vendor/bin/pint && php artisan test && npm run build`
Expected: 全テスト PASS（このブランチの新規テスト19件を含む）、Pint PASS、ビルド成功

もしテストが落ちる場合は競合解決を見直してください。この時点では既存の不具合（Task 2・3 で直すもの）はテストで検知されないため、テストは通るはずです。

- [ ] **Step 6: マージをコミット**

```bash
git add -A
git commit -m "Merge branch 'claude/timestamp-search-delimiter-noise-w8i3pt' into feature/song-fuzzy-search"
```

---

### Task 2: 複合語のノイズワードが除去されない問題を修正

**Files:**
- Modify: `app/Helpers/QueryHelper.php`（`splitFuzzyKeywords()` とその補助メソッド）
- Test: `tests/Unit/Helpers/QueryHelperTest.php`（既存ファイルに追記）

**Interfaces:**
- Consumes: `QueryHelper::splitFuzzyKeywords(string $search): array`（Task 1）
- Produces: 挙動の変更のみ。シグネチャは変わらない

`TextNormalizer::IGNORE_KEYWORDS` には `music video` のようにスペースを含む複合語がある。一方 `splitFuzzyKeywords()` はテキストを単語単位に分割した**後**で `in_array()` の完全一致比較をしているため、`music` がどのエントリとも一致せず検索語として残る。その結果 `夜に駆ける / YOASOBI (Music Video)` の検索が0件になる。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Helpers/QueryHelperTest.php` の末尾（クラスの閉じ括弧の直前）に追加する。

```php
    /**
     * スペースを含む複合語のノイズワードも除去されること
     *
     * IGNORE_KEYWORDS の 'music video' は複合語だが、検索キーワードは単語単位に
     * 分割されるため、分解して比較しないと 'music' が検索語として残ってしまう
     */
    public function test_split_fuzzy_keywords_removes_compound_ignore_keyword(): void
    {
        $this->assertEquals(
            ['夜に駆ける', 'yoasobi'],
            QueryHelper::splitFuzzyKeywords('夜に駆ける / YOASOBI (Music Video)')
        );

        $this->assertEquals(
            ['ロキ', 'みきとp'],
            QueryHelper::splitFuzzyKeywords('ロキ / みきとP【Official Music Video】')
        );
    }

    /**
     * 全てがノイズワードのときは除去前のキーワードを使うこと
     *
     * 除去した結果が空になると全件がヒットしてしまうため
     */
    public function test_split_fuzzy_keywords_keeps_all_when_everything_is_noise(): void
    {
        $this->assertEquals(
            ['music', 'video'],
            QueryHelper::splitFuzzyKeywords('Music Video')
        );
    }
```

- [ ] **Step 2: テストを実行して失敗を確認**

Run: `php artisan test --filter=test_split_fuzzy_keywords_removes_compound_ignore_keyword`
Expected: FAIL（`['夜に駆ける', 'yoasobi', 'music']` が返り、`music` が残っている）

- [ ] **Step 3: 無視ワードを同じ単位に分解する処理を追加**

`app/Helpers/QueryHelper.php` の `splitFuzzyKeywords()` の中で、無視ワードを取得している箇所を書き換える。変更前:

```php
        // ノイズワード（cover、mv など）を除去
        $ignoreKeywords = TextNormalizer::getIgnoreKeywords();
        $filtered = array_values(array_filter(
            $keywords,
            fn ($keyword) => ! in_array($keyword, $ignoreKeywords, true)
        ));
```

変更後:

```php
        // ノイズワード（cover、mv など）を除去
        $ignoreTokens = self::tokenizeIgnoreKeywords();
        $filtered = array_values(array_filter(
            $keywords,
            fn ($keyword) => ! in_array($keyword, $ignoreTokens, true)
        ));
```

そして `splitFuzzyKeywords()` メソッドの直後に、次のメソッドを追加する。

```php
    /**
     * 無視キーワードを検索キーワードと同じ単位に分解する
     *
     * IGNORE_KEYWORDS には 'music video' のようにスペースを含む複合語がある。
     * 検索キーワードは単語単位に分割されているため、そのまま比較すると
     * 'music' がどのエントリとも一致せず検索語として残ってしまう。
     *
     * 分解の副作用として 'music' 単独もノイズ扱いになるが、
     * 全てがノイズだった場合は除去前のキーワードを使うフォールバックがあるため、
     * 「Music」だけで検索した場合は潰れない。
     *
     * @return string[]
     */
    private static function tokenizeIgnoreKeywords(): array
    {
        $tokens = [];

        foreach (TextNormalizer::getIgnoreKeywords() as $keyword) {
            $split = preg_split(self::FUZZY_TOKEN_PATTERN, $keyword, -1, PREG_SPLIT_NO_EMPTY);

            if ($split === false) {
                continue;
            }

            foreach ($split as $token) {
                $tokens[] = $token;
            }
        }

        return array_values(array_unique($tokens));
    }
```

分割パターンを2箇所で使うので、クラスの定数として切り出す。クラス冒頭（他の定数の近く、なければクラス宣言の直後）に追加する。

```php
    /**
     * あいまい検索でキーワードを区切る文字（文字・数字以外すべて）
     */
    private const FUZZY_TOKEN_PATTERN = '/[^\p{L}\p{Nd}]+/u';
```

`splitFuzzyKeywords()` の中でトークン分割している箇所も、この定数を使うように書き換える。変更前:

```php
        $tokens = preg_split('/[^\p{L}\p{Nd}]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
```

変更後:

```php
        $tokens = preg_split(self::FUZZY_TOKEN_PATTERN, $normalized, -1, PREG_SPLIT_NO_EMPTY);
```

- [ ] **Step 4: テストを実行して成功を確認**

Run: `php artisan test --filter=QueryHelperTest`
Expected: PASS（既存13件 + 追加2件）

- [ ] **Step 5: 実際に候補が返ることを確認**

あいまい検索が機能しているかを、コントローラ経由で確認するテストを `tests/Feature/SongControllerTest.php` の末尾（クラスの閉じ括弧の直前）に追加する。

```php
    /**
     * 「(Music Video)」付きのテキストでも楽曲マスタが見つかること
     *
     * 複合語のノイズワードが除去されないと検索語に 'music' が残り0件になる
     */
    public function test_fetch_songs_finds_song_with_compound_noise_in_search(): void
    {
        $this->actingAs(User::factory()->create());

        Song::factory()->create(['title' => '夜に駆ける', 'artist' => 'YOASOBI']);

        $response = $this->getJson('/api/songs?'.http_build_query([
            'search' => '夜に駆ける / YOASOBI (Music Video)',
        ]));

        $response->assertOk();
        $this->assertEquals(1, $response->json('total'));
        $this->assertEquals('夜に駆ける', $response->json('data.0.title'));
    }
```

Run: `php artisan test --filter=test_fetch_songs_finds_song_with_compound_noise_in_search`
Expected: PASS

もし `total` が `null` になる場合は、`/api/songs` のレスポンス構造（`data` / `total` の有無）を `SongController::fetchSongs()` で確認し、実際の構造に合わせてアサーションを直してください。

- [ ] **Step 6: 整形と全テスト**

Run: `./vendor/bin/pint && php artisan test`
Expected: Pint PASS、全テスト PASS

- [ ] **Step 7: コミット**

```bash
git add app/Helpers/QueryHelper.php tests/Unit/Helpers/QueryHelperTest.php tests/Feature/SongControllerTest.php
git commit -m "fix: 複合語のノイズワードが除去されずあいまい検索が0件になる問題を修正"
```

---

### Task 3: 先頭の数値トークンが1個しか除去されない問題を修正

**Files:**
- Modify: `app/Helpers/QueryHelper.php`（`splitFuzzyKeywords()`）
- Test: `tests/Unit/Helpers/QueryHelperTest.php`（既存ファイルに追記）

**Interfaces:**
- Consumes: `QueryHelper::splitFuzzyKeywords(string $search): array`（Task 1、Task 2 で修正済み）
- Produces: 挙動の変更のみ

`00:12:34 ロキ / みきとP` のようにタイムスタンプ込みで貼り付けると、トークンは `['00', '12', '34', 'ロキ', 'みきとp']` になる。現在の実装は先頭の1個しか除去しないため `12` と `34` が検索語として残り0件になる。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Helpers/QueryHelperTest.php` の末尾（クラスの閉じ括弧の直前）に追加する。

```php
    /**
     * 先頭に連続する数値トークンをまとめて除去すること
     *
     * "00:12:34 曲名 / アーティスト" のようにタイムスタンプ込みで
     * 貼り付けられても検索できるようにする
     */
    public function test_split_fuzzy_keywords_removes_leading_number_tokens(): void
    {
        $this->assertEquals(
            ['ロキ', 'みきとp'],
            QueryHelper::splitFuzzyKeywords('00:12:34 ロキ / みきとP')
        );

        $this->assertEquals(
            ['ロキ', 'みきとp'],
            QueryHelper::splitFuzzyKeywords('1. ロキ / みきとP')
        );
    }

    /**
     * 数字だけの検索は潰さないこと
     */
    public function test_split_fuzzy_keywords_keeps_number_only_search(): void
    {
        $this->assertEquals(['123'], QueryHelper::splitFuzzyKeywords('123'));
        $this->assertEquals(['34'], QueryHelper::splitFuzzyKeywords('00:12:34'));
    }
```

- [ ] **Step 2: テストを実行して失敗を確認**

Run: `php artisan test --filter=test_split_fuzzy_keywords_removes_leading_number_tokens`
Expected: FAIL（`['12', '34', 'ロキ', 'みきとp']` が返る）

- [ ] **Step 3: 連続する先頭数値トークンを除去するよう修正**

`app/Helpers/QueryHelper.php` の `splitFuzzyKeywords()` 内、先頭トークンを除去している箇所を書き換える。変更前:

```php
        // 先頭の曲番号（"1." "01" など）を除去
        // 他にキーワードがある場合のみ除去する（数字だけの検索を潰さないため）
        if (count($tokens) >= 2 && preg_match('/^\p{Nd}+$/u', $tokens[0]) === 1) {
            array_shift($tokens);
        }
```

変更後:

```php
        // 先頭の数値トークン（曲番号の "1." や "00:12:34" のようなタイムスタンプ）を除去
        // 他にキーワードが残る場合のみ除去する（数字だけの検索を潰さないため）
        while (count($tokens) >= 2 && preg_match('/^\p{Nd}+$/u', $tokens[0]) === 1) {
            array_shift($tokens);
        }
```

- [ ] **Step 4: テストを実行して成功を確認**

Run: `php artisan test --filter=QueryHelperTest`
Expected: PASS

- [ ] **Step 5: 検索欄のヒント文言を実態に合わせる**

`resources/views/songs/index.blade.php` のあいまい検索ボタンの `title` 属性は「タイムスタンプをそのまま貼り付けて検索できます。」となっている。Task 3 の修正で実際にそうなったので文言はそのままでよい。変更は不要。この Step は確認のみ。

Run: `grep -n 'タイムスタンプをそのまま貼り付け' resources/views/songs/index.blade.php`
Expected: あいまい検索ボタンの title 属性に該当行が出る

- [ ] **Step 6: 整形と全テスト**

Run: `./vendor/bin/pint && php artisan test && npm run build`
Expected: Pint PASS、全テスト PASS、ビルド成功

- [ ] **Step 7: コミット**

```bash
git add app/Helpers/QueryHelper.php tests/Unit/Helpers/QueryHelperTest.php
git commit -m "fix: 先頭の数値トークンが1個しか除去されずタイムスタンプ貼り付けで0件になる問題を修正"
```

---

## Phase 2: 候補タブ

Phase 1 を develop にマージした後、`feature/song-candidate-tab` ブランチを develop に追従させてから始める。

```bash
git checkout feature/song-candidate-tab
git merge origin/develop
```

### Task 4: 候補取得API

**Files:**
- Modify: `app/Http/Controllers/SongController.php`（メソッドを1つ追加）
- Modify: `routes/web.php`（`api/songs/candidates` を追加）
- Test: `tests/Feature/SongCandidatesApiTest.php`（新規）

**Interfaces:**
- Consumes: `QueryHelper::applyFuzzySearch(Builder $query, string $search, array $columns): Builder`（Phase 1）、`TextNormalizer::splitBySeparators(?string $text): array`（`parts` / `separator_count` / `has_separators` / `original` を持つ配列を返す）、`TextNormalizer::isIgnorablePart(string $part): bool`
- Produces: `GET /api/songs/candidates?text=<元テキスト>` が `{ parts: string[], ignored_indices: int[], songs: object[], total: int }` を返す。名前付きルート `songs.candidates`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/SongCandidatesApiTest.php` を新規作成する。

```php
<?php

namespace Tests\Feature;

use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 選択したタイムスタンプに対する楽曲マスタの候補取得API
 */
class SongCandidatesApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 未認証ならログイン画面にリダイレクトされること
     */
    public function test_guest_is_rejected(): void
    {
        $this->get('/api/songs/candidates?text=test')->assertRedirect('/login');
    }

    /**
     * 元テキストが区切り文字で分割されて返ること
     */
    public function test_returns_parts_split_by_separators(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->getJson('/api/songs/candidates?'.http_build_query([
            'text' => '今日から思い出 / Aimer',
        ]));

        $response->assertOk();
        $response->assertJsonPath('parts', ['今日から思い出', 'Aimer']);
    }

    /**
     * 無視対象のパーツの位置が ignored_indices に入ること
     */
    public function test_returns_ignored_indices(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->getJson('/api/songs/candidates?'.http_build_query([
            'text' => '今日から思い出 / Aimer / cover',
        ]));

        $response->assertOk();
        $response->assertJsonPath('parts', ['今日から思い出', 'Aimer', 'cover']);
        $response->assertJsonPath('ignored_indices', [2]);
    }

    /**
     * 同じ文字列のパーツが複数あっても位置がずれないこと
     */
    public function test_ignored_indices_are_positions_not_values(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->getJson('/api/songs/candidates?'.http_build_query([
            'text' => 'cover / 今日から思い出 / cover',
        ]));

        $response->assertOk();
        $response->assertJsonPath('parts', ['cover', '今日から思い出', 'cover']);
        $response->assertJsonPath('ignored_indices', [0, 2]);
    }

    /**
     * 無視対象を除いたパーツで検索した候補が返ること
     */
    public function test_returns_candidates_searched_without_ignored_parts(): void
    {
        $this->actingAs(User::factory()->create());

        $target = Song::factory()->create(['title' => '今日から思い出', 'artist' => 'Aimer']);
        Song::factory()->create(['title' => '全く別の曲', 'artist' => '別のアーティスト']);

        $response = $this->getJson('/api/songs/candidates?'.http_build_query([
            'text' => '今日から思い出 / Aimer (cover)',
        ]));

        $response->assertOk();
        $this->assertEquals(1, $response->json('total'));
        $this->assertEquals($target->id, $response->json('songs.0.id'));
    }

    /**
     * 全パーツが無視対象のときは検索せず候補を空で返すこと
     *
     * 検索語が無い状態で検索すると全件がヒットしてしまうため
     */
    public function test_returns_empty_candidates_when_all_parts_are_ignorable(): void
    {
        $this->actingAs(User::factory()->create());

        Song::factory()->create(['title' => '今日から思い出', 'artist' => 'Aimer']);

        $response = $this->getJson('/api/songs/candidates?'.http_build_query([
            'text' => 'cover / MV',
        ]));

        $response->assertOk();
        $response->assertJsonPath('ignored_indices', [0, 1]);
        $this->assertEquals(0, $response->json('total'));
        $this->assertEquals([], $response->json('songs'));
    }

    /**
     * 記号だけのテキストで壊れないこと
     */
    public function test_handles_symbol_only_text(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->getJson('/api/songs/candidates?'.http_build_query([
            'text' => '/ - /',
        ]));

        $response->assertOk();
        $this->assertEquals(0, $response->json('total'));
    }

    /**
     * text が無いと422になること
     */
    public function test_requires_text(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/songs/candidates')->assertStatus(422);
    }
}
```

- [ ] **Step 2: テストを実行して失敗を確認**

Run: `php artisan test --filter=SongCandidatesApiTest`
Expected: FAIL（404。ルートが未定義）

- [ ] **Step 3: ルートを追加**

`routes/web.php` の `api/songs` 系のルートが並んでいる箇所（`Route::get('api/songs', [SongController::class, 'fetchSongs'])` の直後）に追加する。

```php
    Route::get('api/songs/candidates', [SongController::class, 'candidates'])->name('songs.candidates');
```

- [ ] **Step 4: Controller にメソッドを追加**

`app/Http/Controllers/SongController.php` の `fetchSongs()` メソッドの直後に追加する。

```php
    /**
     * 選択したタイムスタンプに対する楽曲マスタの候補を返す
     *
     * 元テキストを区切り文字で分割したパーツと、そのうちノイズと判定した位置、
     * ノイズを除いたパーツで検索した候補をまとめて返す。
     * 正規化画面の「候補」タブが、タイムスタンプを選んだ時点で1回だけ叩く。
     */
    public function candidates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'text' => 'required|string|max:500',
        ]);

        $parts = TextNormalizer::splitBySeparators($validated['text'])['parts'];

        $ignoredIndices = [];
        $searchParts = [];

        foreach ($parts as $index => $part) {
            if (TextNormalizer::isIgnorablePart($part)) {
                $ignoredIndices[] = $index;

                continue;
            }

            $searchParts[] = $part;
        }

        $songs = [];
        $total = 0;

        // 検索語が無い状態で検索すると全件がヒットしてしまうため、その場合は空で返す
        if ($searchParts !== []) {
            $query = Song::query();
            QueryHelper::applyFuzzySearch(
                $query,
                implode(' ', $searchParts),
                ['normalized_title', 'normalized_artist']
            );

            $total = $query->count();
            $songs = $query->orderBy('title')->limit(self::CANDIDATE_LIMIT)->get();
        }

        return response()->json([
            'parts' => $parts,
            'ignored_indices' => $ignoredIndices,
            'songs' => $songs,
            'total' => $total,
        ]);
    }
```

クラス冒頭の定数定義の近く（`SEARCH_MODE_EXACT` の直後）に、件数上限の定数を追加する。

```php
    /**
     * 候補として返す最大件数
     */
    private const CANDIDATE_LIMIT = 50;
```

`use` 宣言に不足があれば追加する（`App\Helpers\QueryHelper`, `App\Helpers\TextNormalizer`, `Illuminate\Http\JsonResponse`, `App\Models\Song`）。既にあるものは重複させないこと。追加後に `./vendor/bin/pint` を実行すれば並び順は整う。

- [ ] **Step 5: テストを実行して成功を確認**

Run: `php artisan test --filter=SongCandidatesApiTest`
Expected: PASS（8 tests）

`test_guest_is_rejected` が 401 を返して落ちる場合は、`EnsureUserIsAdmin` が JSON リクエストに 401 を返す実装であるためです。その場合はテストを `assertRedirect('/login')` から実際の挙動に合わせて直してください（`$this->get()` はJSONを期待しないので通常はリダイレクトになります）。

- [ ] **Step 6: 整形と全テスト**

Run: `./vendor/bin/pint && php artisan test`
Expected: Pint PASS、全テスト PASS

- [ ] **Step 7: コミット**

```bash
git add app/Http/Controllers/SongController.php routes/web.php tests/Feature/SongCandidatesApiTest.php
git commit -m "feat: 選択したタイムスタンプに対する楽曲マスタの候補取得APIを追加"
```

---

### Task 5: 候補タブの追加と候補・チップの表示

**Files:**
- Modify: `resources/views/songs/index.blade.php`（タブボタンとタブ内容を追加）
- Modify: `resources/js/songs/services/SongApiService.js`（候補取得メソッドを追加）
- Modify: `resources/js/songs/normalize.js`（タブ切替・候補取得・描画）

**Interfaces:**
- Consumes: `GET /api/songs/candidates?text=<元テキスト>` → `{ parts, ignored_indices, songs, total }`（Task 4）
- Produces: `songApiService.fetchCandidates(text)` / `this.candidateParts`（`string[]`）/ `this.candidateSelectedIndices`（`Set<number>`）/ `loadCandidates()` / `renderCandidateChips()` / `displayCandidates(songs, total)`

このタスクは JavaScript が中心で、自動テストの基盤がない（Issue #665）。Step 6 の Playwright による確認をもって検証とする。

- [ ] **Step 1: タブボタンとタブ内容を追加**

`resources/views/songs/index.blade.php` のタブ `<nav>` の中、`songsTab` ボタンの直後に追加する。

```blade
                                <button id="candidatesTab" class="tab-button px-3 py-2 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 -mb-px">
                                    候補
                                </button>
```

タブ内容は、既存の `<div id="songsList" class="tab-content ...">` の直後に追加する。

```blade
                        <!-- 選択したタイムスタンプに対する候補 -->
                        <div id="candidatesList" class="tab-content hidden">
                            <div id="candidateNotice" class="text-sm text-gray-500 dark:text-gray-400 mb-3"></div>

                            <div id="candidateChipsArea" class="mb-3 hidden">
                                <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">絞り込み（押した語で検索します）</div>
                                <div id="candidateChips" class="flex flex-wrap gap-2"></div>
                            </div>

                            <div id="candidateResults"></div>
                        </div>
```

- [ ] **Step 2: API 呼び出しメソッドを追加**

`resources/js/songs/services/SongApiService.js` の `fetchSongs()` メソッドの直後に追加する。

```javascript
    /**
     * 選択したタイムスタンプに対する楽曲マスタの候補を取得
     * @param {string} text - タイムスタンプの元テキスト
     * @returns {Promise<Object>} { parts, ignored_indices, songs, total }
     */
    async fetchCandidates(text) {
        const response = await axios.get('/api/songs/candidates', { params: { text } });

        return response.data;
    }
```

既存の `fetchSongs()` と同じ形になっているか確認すること。`axios` の import 方法、`response.data` の取り出し方、エラーを呼び出し側に投げる方針（このメソッドでは catch しない）を合わせる。

- [ ] **Step 3: 状態とタブ切替を追加**

`resources/js/songs/normalize.js` のコンストラクタに状態を追加する（既存のプロパティ群の末尾）。

```javascript
        this.candidateParts = [];              // 候補タブのチップ（元テキストの分割結果）
        this.candidateSelectedIndices = new Set(); // 選択中のチップの位置
        this.candidateTextKey = null;          // どのタイムスタンプのチップかを判別する元テキスト
```

`showTab()` に候補タブの分岐を追加する。`songsTab` の分岐（`} else if (tabId === 'songsTab') {` のブロック）の直後に追加する。

```javascript
        } else if (tabId === 'candidatesTab') {
            activeTab.classList.remove('border-transparent', 'text-gray-500');
            activeTab.classList.add('border-amber-500', 'text-amber-600');
            document.getElementById('candidatesList').classList.remove('hidden');

            this.loadCandidates();
        }
```

タブボタンのクリックは `.tab-button` クラスに対して一括登録されている（`resources/js/songs/normalize.js:129-130` の `document.querySelectorAll('.tab-button')`）。Step 1 で追加したボタンに `tab-button` クラスを付けてあるので、イベント登録の追加は不要。

あわせて `showTab()` の冒頭でタブの選択状態クラスを一括削除している箇所に、候補タブで使う amber 系を追加する。変更前:

```javascript
        document.querySelectorAll('.tab-button').forEach(btn => {
            btn.classList.remove('border-green-500', 'text-green-600', 'border-blue-500', 'text-blue-600', 'border-purple-500', 'text-purple-600');
            btn.classList.add('border-transparent', 'text-gray-500');
        });
```

変更後:

```javascript
        document.querySelectorAll('.tab-button').forEach(btn => {
            btn.classList.remove('border-green-500', 'text-green-600', 'border-blue-500', 'text-blue-600', 'border-purple-500', 'text-purple-600', 'border-amber-500', 'text-amber-600');
            btn.classList.add('border-transparent', 'text-gray-500');
        });
```

これを忘れると、候補タブから他のタブへ移っても候補タブが選択中の見た目のまま残る。

- [ ] **Step 4: 候補の取得と描画を実装**

`resources/js/songs/normalize.js` の `displaySongs()` メソッドの直後に追加する。

```javascript
    /**
     * 候補タブの内容を読み込む
     *
     * タイムスタンプが1件だけ選択されているときに候補を取得する。
     * 複数選択中は選択に触らず案内だけ出す（一括紐付けの選択を壊さないため）。
     */
    async loadCandidates() {
        const notice = document.getElementById('candidateNotice');
        const chipsArea = document.getElementById('candidateChipsArea');
        const results = document.getElementById('candidateResults');

        if (this.selectedTimestamps.length === 0) {
            notice.textContent = 'タイムスタンプを1件選ぶと候補を表示します。';
            chipsArea.classList.add('hidden');
            results.innerHTML = '';
            return;
        }

        if (this.selectedTimestamps.length > 1) {
            this.renderMultiSelectionNotice();
            chipsArea.classList.add('hidden');
            results.innerHTML = '';
            return;
        }

        const text = this.selectedTimestamps[0].text;

        // 同じテキストのチップが既にあるなら、選択状態を保ったまま再検索する
        if (this.candidateTextKey === text) {
            await this.searchCandidatesByChips();
            return;
        }

        notice.textContent = '候補を探しています…';
        results.innerHTML = '';

        try {
            const data = await songApiService.fetchCandidates(text);

            this.candidateTextKey = text;
            this.candidateParts = data.parts;
            this.candidateSelectedIndices = new Set(
                data.parts.map((_, i) => i).filter(i => !data.ignored_indices.includes(i))
            );

            this.renderCandidateChips();
            notice.textContent = '';
            this.displayCandidates(data.songs, data.total);
        } catch (error) {
            console.error('候補の取得に失敗しました:', error);
            notice.textContent = '候補の取得に失敗しました。';
            chipsArea.classList.add('hidden');
        }
    }

    /**
     * 複数選択中の案内を表示する
     */
    renderMultiSelectionNotice() {
        const notice = document.getElementById('candidateNotice');
        notice.textContent = '';

        const message = document.createElement('p');
        message.className = 'mb-2';
        message.textContent = `${this.selectedTimestamps.length}件選択中です。候補を見るには1件だけ選んでください。`;

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'px-3 py-1 bg-amber-600 text-white text-sm rounded hover:bg-amber-700';
        button.textContent = '最後に選んだ1件に絞る';
        button.addEventListener('click', () => {
            this.selectedTimestamps = this.selectedTimestamps.slice(-1);
            this.updateSelectionDisplay();
            this.loadTimestamps(this.currentPage, this.currentSearchQuery);
            this.loadCandidates();
        });

        notice.appendChild(message);
        notice.appendChild(button);
    }

    /**
     * チップを描画する
     */
    renderCandidateChips() {
        const chipsArea = document.getElementById('candidateChipsArea');
        const container = document.getElementById('candidateChips');

        container.innerHTML = '';

        if (this.candidateParts.length === 0) {
            chipsArea.classList.add('hidden');
            return;
        }

        chipsArea.classList.remove('hidden');

        this.candidateParts.forEach((part, index) => {
            const selected = this.candidateSelectedIndices.has(index);
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.textContent = part;
            chip.className = `px-2 py-1 text-xs rounded border ${
                selected
                    ? 'bg-amber-600 text-white border-amber-600'
                    : 'border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'
            }`;
            chip.addEventListener('click', () => this.toggleCandidateChip(index));
            container.appendChild(chip);
        });
    }

    /**
     * 候補一覧を描画する
     *
     * 候補の見た目と選択の扱いは楽曲マスタ一覧と揃える（createSongElement を再利用する）
     */
    displayCandidates(songs, total) {
        const results = document.getElementById('candidateResults');
        const notice = document.getElementById('candidateNotice');

        results.innerHTML = '';

        if (!Array.isArray(songs) || songs.length === 0) {
            notice.textContent = this.candidateSelectedIndices.size === 0
                ? '絞り込みの語を1つ以上選んでください。'
                : '候補が見つかりませんでした。チップを外して条件を緩めてください。';
            return;
        }

        notice.textContent = `${total}件の候補`;

        songs.forEach(song => {
            results.appendChild(this.createSongElement(song, songs, total));
        });
    }

    /**
     * チップの選択を切り替えて再検索する
     */
    async toggleCandidateChip(index) {
        if (this.candidateSelectedIndices.has(index)) {
            this.candidateSelectedIndices.delete(index);
        } else {
            this.candidateSelectedIndices.add(index);
        }

        this.renderCandidateChips();
        await this.searchCandidatesByChips();
    }

    /**
     * 選択中のチップの語で候補を再検索する
     */
    async searchCandidatesByChips() {
        const results = document.getElementById('candidateResults');

        const words = this.candidateParts.filter((_, i) => this.candidateSelectedIndices.has(i));

        if (words.length === 0) {
            results.innerHTML = '';
            this.displayCandidates([], 0);
            return;
        }

        try {
            const response = await songApiService.fetchSongs(
                words.join(' '),
                null,
                CONSTANTS.SONG_SEARCH_MODE_FUZZY
            );
            const songs = response.data ?? response;
            this.displayCandidates(songs, response.total ?? songs.length);
        } catch (error) {
            console.error('候補の検索に失敗しました:', error);
            document.getElementById('candidateNotice').textContent = '候補の検索に失敗しました。';
        }
    }
```

`createSongElement(song, songs, total)` は既存メソッド（`resources/js/songs/normalize.js:910`）で、楽曲マスタ一覧の描画に使われている。第2引数は同じ一覧に並ぶ楽曲の配列、第3引数は総件数で、どちらも選択解除時の再描画に使われる。候補一覧でも同じ意味で渡せばよい（上のコードはそうなっている）。

- [ ] **Step 5: タイムスタンプ選択時に候補を更新する**

`toggleTimestampSelection()` の末尾（Spotify検索欄へ反映している処理の直後）に追加する。

```javascript
        // 候補タブを開いている場合は候補を更新する
        if (!document.getElementById('candidatesList').classList.contains('hidden')) {
            this.loadCandidates();
        }
```

- [ ] **Step 6: 実際に動かして確認**

ビルドしてから、ローカルサーバーと Playwright で確認する。

```bash
npm run build
```

ローカル開発用ログインを一時的に有効化する（確認後に必ず元へ戻す）。

```bash
cp .env .env.backup-verify
grep -q DEV_LOGIN_ENABLED .env || printf '\nDEV_LOGIN_ENABLED=true\n' >> .env
php artisan config:clear
php artisan serve --port=8899 &
```

ブラウザで確認する内容:

1. タイムスタンプを1件選び、候補タブを開くと候補が出る
2. チップを押し引きすると候補が変わる
3. チップを全て外すと「絞り込みの語を1つ以上選んでください。」が出る
4. 該当のない語だけを選ぶと「候補が見つかりませんでした。」が出る
5. 複数選択中に候補タブを開くと、選択が保持されたまま案内が出る
6. 「最後に選んだ1件に絞る」ボタンで1件になり候補が出る
7. ダークモードとスマホ幅（390px）で崩れない

確認が終わったら必ず後始末する。

```bash
pkill -f "artisan serve --port=8899"
mv .env.backup-verify .env
php artisan config:clear
```

- [ ] **Step 7: 整形・テスト・コミット**

Run: `./vendor/bin/pint && php artisan test && npm run build`
Expected: すべて成功（このタスクは JS 中心なので PHP テストの件数は変わらない）

```bash
git add resources/views/songs/index.blade.php resources/js/songs/normalize.js resources/js/songs/services/SongApiService.js
git commit -m "feat: 選択したタイムスタンプの候補を表示する候補タブを追加"
```

---

### Task 6: 候補タブでタイムスタンプを単一選択にする

**Files:**
- Modify: `resources/js/songs/normalize.js`（`createTimestampElement()` / `toggleTimestampSelection()` / `selectAll()` / `showTab()`）

**Interfaces:**
- Consumes: `this.candidateParts` / `loadCandidates()`（Task 5）
- Produces: `isCandidateTabActive()` が候補タブが開いているかを返す

- [ ] **Step 1: 候補タブが開いているかを判定するメソッドを追加**

`loadCandidates()` の直前に追加する。

```javascript
    /**
     * 候補タブが開いているか
     */
    isCandidateTabActive() {
        return !document.getElementById('candidatesList').classList.contains('hidden');
    }
```

Task 5 の Step 5 で追加した判定と、`loadCandidates()` 内の判定もこのメソッドを使うように置き換える。

- [ ] **Step 2: 選択が1件以下ならラジオボタンで描画する**

`createTimestampElement()` のチェックボックス生成部分を書き換える。変更前:

```javascript
        // チェックボックス
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.checked = isSelected;
```

変更後:

```javascript
        // 候補タブは1件のテキストに対する候補を出すため単一選択にする。
        // ただし複数選択中は、複数がチェックされたラジオボタンという矛盾した表示に
        // ならないようチェックボックスのまま描画する（選択を保持したまま案内を出す）
        const singleSelect = this.isCandidateTabActive() && this.selectedTimestamps.length <= 1;

        const checkbox = document.createElement('input');
        checkbox.type = singleSelect ? 'radio' : 'checkbox';
        if (singleSelect) {
            checkbox.name = 'candidateTimestamp';
        }
        checkbox.checked = isSelected;
```

- [ ] **Step 3: 候補タブでは単一選択にする**

`toggleTimestampSelection()` の冒頭を書き換える。変更前:

```javascript
    toggleTimestampSelection(timestamp) {
        const index = this.selectedTimestamps.findIndex(t => t.id === timestamp.id);

        if (index >= 0) {
            this.selectedTimestamps.splice(index, 1);
        } else {
            this.selectedTimestamps.push(timestamp);
        }
```

変更後:

```javascript
    toggleTimestampSelection(timestamp) {
        const index = this.selectedTimestamps.findIndex(t => t.id === timestamp.id);

        if (this.isCandidateTabActive()) {
            // 候補タブでは単一選択。同じ行を選び直したときは解除できるようにする
            this.selectedTimestamps = index >= 0 ? [] : [timestamp];
        } else if (index >= 0) {
            this.selectedTimestamps.splice(index, 1);
        } else {
            this.selectedTimestamps.push(timestamp);
        }
```

- [ ] **Step 4: 候補タブでは全選択を無効にする**

`selectAll()` の冒頭に追加する。

```javascript
    selectAll() {
        // 候補タブは単一選択なので全選択はしない
        if (this.isCandidateTabActive()) {
            return;
        }
```

あわせて `showTab()` の候補タブ分岐で「全選択」ボタンを見た目でも無効にする。Task 5 の Step 3 で追加した `candidatesTab` の分岐に追加する。

```javascript
            document.getElementById('selectAllBtn').disabled = true;
```

他のタブに切り替えたときに戻す必要があるため、`showTab()` の冒頭（タブ内容を全て隠している処理の近く）に追加する。

```javascript
        // 候補タブ以外では全選択を使えるようにする
        document.getElementById('selectAllBtn').disabled = false;
```

この行は各タブの分岐より前に置くこと。候補タブの分岐で `true` に戻すため、順序が逆になると効かない。

- [ ] **Step 5: 選択状態の変化でラジオ/チェックボックスの表示を切り替える**

`showTab()` の候補タブ分岐で `loadCandidates()` を呼ぶ前に、一覧を再描画して input の種類を切り替える。

```javascript
            this.loadTimestamps(this.currentPage, this.currentSearchQuery);
```

`toggleTimestampSelection()` は既に `loadTimestamps()` を呼んでいるため、選択のたびに表示は更新される。追加は不要。

- [ ] **Step 6: 実際に動かして確認**

Task 5 の Step 6 と同じ手順でサーバーを立て、次を確認する。

1. 候補タブを開くとタイムスタンプがラジオボタンになる
2. 別の行を選ぶと前の選択が外れる
3. 同じ行を選び直すと解除される
4. 候補タブでは「全選択」ボタンが押せない
5. 複数選択したまま候補タブを開くとチェックボックスのままで、選択が保持される
6. 他のタブに戻ると複数選択に戻り、「全選択」が押せる

確認後は Task 5 の Step 6 と同じ後始末を行う。

- [ ] **Step 7: 整形・テスト・コミット**

Run: `./vendor/bin/pint && php artisan test && npm run build`
Expected: すべて成功

```bash
git add resources/js/songs/normalize.js
git commit -m "feat: 候補タブではタイムスタンプを単一選択にする"
```

---

## 完了後の確認

- [ ] `php artisan test` が全て PASS
- [ ] `./vendor/bin/pint --test` が PASS
- [ ] `npm run build` が成功
- [ ] Phase 1 が develop にマージ済みで、Phase 2 のブランチがそれに追従している
- [ ] 設計書の「手動確認」に挙げた項目をすべて実機で確認した
