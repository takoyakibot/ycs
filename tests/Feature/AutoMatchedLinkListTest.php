<?php

namespace Tests\Feature;

use App\Helpers\TextNormalizer;
use App\Models\Song;
use App\Models\TimestampDecomposition;
use App\Models\TimestampSongMapping;
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
     * 紐付け済みは「判定時」列に楽曲マスタの値が表示されること
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
    }

    /**
     * auto_matched 以外は表示されないこと
     */
    public function test_does_not_show_other_statuses(): void
    {
        $this->actingAs(User::factory()->create());

        $this->createDecomposition('自動判定のテキスト / アーティスト');
        $this->createDecomposition('処理待ちのテキスト / アーティスト', [
            'status' => TimestampDecomposition::STATUS_PENDING,
        ]);

        $this->get(route('songs.decompose.linked'))
            ->assertOk()
            ->assertSee('自動判定のテキスト')
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
            ->assertSee('filter=unlinked&amp;page=2', false);
    }

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

    /**
     * 総件数0件なら「該当するアイテムがありません」が出ること
     *
     * ページ番号を付けても文言が変わらないことを併せて見る。総件数が0なら
     * 他のページにも行は無いので、「このページには」と読める文言を出してはいけない。
     */
    public function test_shows_empty_message_when_no_records(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('songs.decompose.linked'))
            ->assertOk()
            ->assertSee('該当するアイテムがありません。')
            ->assertDontSee('このページには表示する行がありません。');

        // total()=0 でも currentPage() はクランプされない（実測: page=5 なら 5 のまま）
        $this->get(route('songs.decompose.linked', ['page' => 5]))
            ->assertOk()
            ->assertSee('該当するアイテムがありません。')
            ->assertDontSee('このページには表示する行がありません。');
    }

    /**
     * 総件数があるのに範囲外のページを開いたときは、専用の文言とページャが出ること
     *
     * filter=unlinked を開いたまま別タブで一括紐付けすると総件数が減り、
     * この状態になる（絞り込みなしの総件数は減らない。linkToSong() は
     * status を変えないため)。
     * ページャが @if/@else の内側にあると1ページ目へ戻るリンクごと消える。
     */
    public function test_out_of_range_page_keeps_pager(): void
    {
        $this->actingAs(User::factory()->create());
        $this->createDecomposition('1件だけのテキスト');

        $this->get(route('songs.decompose.linked', ['page' => 999]))
            ->assertOk()
            ->assertSee('このページには表示する行がありません。')
            ->assertDontSee('該当するアイテムがありません。')
            ->assertSee('aria-label="Pagination Navigation"', false)
            ->assertSee('page=1', false);
    }

    /**
     * 1ページに収まるときはページャを出さないこと（見た目を変えない）
     */
    public function test_does_not_render_pager_when_single_page(): void
    {
        $this->actingAs(User::factory()->create());
        $this->createDecomposition('1件だけのテキスト');

        $this->get(route('songs.decompose.linked'))
            ->assertOk()
            ->assertDontSee('aria-label="Pagination Navigation"', false);
    }

    /**
     * page に数値以外や0以下を渡しても1ページ目として扱われること
     *
     * Laravel が currentPage を1にクランプするため、行は表示される。
     */
    public function test_invalid_page_falls_back_to_first_page(): void
    {
        $this->actingAs(User::factory()->create());
        $this->createDecomposition('1件だけのテキスト');

        foreach (['abc', '0', '-1', ''] as $page) {
            $this->get(route('songs.decompose.linked', ['page' => $page]))
                ->assertOk()
                ->assertSee('1件だけのテキスト')
                ->assertDontSee('該当するアイテムがありません。');
        }
    }

    /**
     * 曲名が空なら警告が出ること
     *
     * cascadeArtistSelection() は候補が全て無視対象だと derived_title が null のまま
     * auto_matched にする。この行は bulkLinkAutoMatched() の whereNotNull('derived_title')
     * で弾かれるため永久に紐付かない（根治は Issue 側）。画面では空欄にせず理由を出す。
     */
    public function test_warns_when_title_is_empty(): void
    {
        $this->actingAs(User::factory()->create());

        $this->createDecomposition('アーティストX / cover', [
            'title_part_index' => null,
            'derived_title' => null,
            'derived_artist' => 'アーティストX',
        ]);

        $this->get(route('songs.decompose.linked'))
            ->assertOk()
            ->assertSee('⚠ 曲名なし')
            ->assertSee('bg-amber-50', false);
    }

    /**
     * アーティストだけが空のときは曲名側の警告を出さないこと
     *
     * 2つの警告を同じ文言にすると、どちらが欠けているのか画面から判別できなくなる。
     */
    public function test_does_not_warn_title_when_only_artist_is_empty(): void
    {
        $this->actingAs(User::factory()->create());

        $song = Song::factory()->create(['title' => 'マスタの曲名', 'artist' => '']);
        $this->createDecomposition('アーティストが空のテキスト', ['song_id' => $song->id]);

        $this->get(route('songs.decompose.linked'))
            ->assertOk()
            ->assertSee('⚠ 未設定')
            ->assertDontSee('⚠ 曲名なし');
    }

    /**
     * マッピングが無い場合、「現在」列に「マッピングなし」が表示されること
     */
    public function test_shows_no_mapping_when_mapping_missing(): void
    {
        $this->actingAs(User::factory()->create());

        $song = Song::factory()->create(['title' => 'マスタの曲名', 'artist' => 'マスタのアーティスト']);
        $this->createDecomposition('マッピングが無いテキスト', ['song_id' => $song->id]);

        $this->get(route('songs.decompose.linked'))
            ->assertOk()
            ->assertSee('マッピングなし');
    }

    /**
     * 「判定時」列は正規化画面での変更が反映されない旨の注記があること
     *
     * 「判定時」列は timestamp_decompositions.song_id を根拠にしており、
     * 正規化画面での解除・付け替えは反映されない。
     * 「現在」列で差分を確認できるが、判定時の表示が古い理由の注記は必要。
     */
    public function test_shows_notice_that_normalization_is_not_reflected(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('songs.decompose.linked'))
            ->assertOk()
            ->assertSee('反映されません');
    }

    /**
     * 元テキストが表示セルにも出ていること
     *
     * コピーボタンの data-copy-text 属性にも同じ値が入るため、
     * 素の assertSee だと属性値だけで充足して表示セルの欠落を見逃す。
     */
    public function test_shows_original_text_in_the_cell_not_only_in_the_button(): void
    {
        $this->actingAs(User::factory()->create());
        $this->createDecomposition('セルに出るべきテキスト');

        $this->get(route('songs.decompose.linked'))
            ->assertOk()
            ->assertSee('<span>セルに出るべきテキスト</span>', false);
    }

    /**
     * 「現在」列に現在のマッピング先の楽曲情報が表示されること
     */
    public function test_shows_current_mapping_song(): void
    {
        $this->actingAs(User::factory()->create());

        $song = Song::factory()->create(['title' => '現在マスタ曲名', 'artist' => '現在マスタアーティスト']);
        $decomposition = $this->createDecomposition('紐付け済みのテキスト', ['song_id' => $song->id]);

        TimestampSongMapping::create([
            'normalized_text' => $decomposition->normalized_text,
            'song_id' => $song->id,
        ]);

        $this->get(route('songs.decompose.linked'))
            ->assertOk()
            ->assertSee('現在マスタ曲名')
            ->assertSee('現在マスタアーティスト');
    }

    /**
     * 現在のマッピングが判定時と異なる場合に「変更あり」バッジが表示されること
     */
    public function test_shows_changed_badge_when_mapping_differs(): void
    {
        $this->actingAs(User::factory()->create());

        $autoSong = Song::factory()->create(['title' => '判定時の曲', 'artist' => 'A']);
        $currentSong = Song::factory()->create(['title' => '現在の曲', 'artist' => 'B']);
        $decomposition = $this->createDecomposition('変更ありのテキスト', ['song_id' => $autoSong->id]);

        TimestampSongMapping::create([
            'normalized_text' => $decomposition->normalized_text,
            'song_id' => $currentSong->id,
        ]);

        $this->get(route('songs.decompose.linked'))
            ->assertOk()
            ->assertSee('変更あり');
    }

    /**
     * filter=changed で現在と異なる行のみ表示されること
     */
    public function test_filter_changed(): void
    {
        $this->actingAs(User::factory()->create());

        $song1 = Song::factory()->create(['title' => '一致の曲']);
        $decomp1 = $this->createDecomposition('一致テキスト', ['song_id' => $song1->id]);
        TimestampSongMapping::create([
            'normalized_text' => $decomp1->normalized_text,
            'song_id' => $song1->id,
        ]);

        $song2 = Song::factory()->create(['title' => '変更の曲']);
        $song3 = Song::factory()->create(['title' => '別の曲']);
        $decomp2 = $this->createDecomposition('変更テキスト', ['song_id' => $song2->id]);
        TimestampSongMapping::create([
            'normalized_text' => $decomp2->normalized_text,
            'song_id' => $song3->id,
        ]);

        $this->get(route('songs.decompose.linked', ['filter' => 'changed']))
            ->assertOk()
            ->assertSee('変更テキスト')
            ->assertDontSee('一致テキスト');
    }

    /**
     * filter に配列を渡しても500にならないこと
     *
     * getAutoMatchedList(?string $filter) に配列が渡ると TypeError になる。
     * これを防いでいるのはコントローラのホワイトリスト検証だけ。
     */
    public function test_array_filter_param_is_ignored(): void
    {
        $this->actingAs(User::factory()->create());

        $song = Song::factory()->create();
        $this->createDecomposition('紐付け済みのテキスト', ['song_id' => $song->id]);
        $this->createDecomposition('未紐付けのテキスト');

        // route() では配列パラメータを組めないので生のパスを使う
        $this->get('/songs/decompose/linked?filter[]=linked')
            ->assertOk()
            ->assertSee('紐付け済みのテキスト')
            ->assertSee('未紐付けのテキスト');
    }

    /**
     * 元テキストに記号が含まれてもコピー属性がエスケープされること
     *
     * original_text はYouTubeの概要欄・コメント由来の外部入力で、
     * エスケープが外れると属性境界を抜けて任意のタグを注入できる。
     */
    public function test_escapes_copy_attribute(): void
    {
        $this->actingAs(User::factory()->create());
        $this->createDecomposition('a"b<c>d');

        $this->get(route('songs.decompose.linked'))
            ->assertOk()
            ->assertSee('data-copy-text="a&quot;b&lt;c&gt;d"', false)
            ->assertDontSee('data-copy-text="a"b', false);
    }
}
