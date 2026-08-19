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
        $response->assertSee('data-status="linked"', false);
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
        $response->assertSee('data-status="unlinked"', false);
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
     * 一覧を開いたまま別タブで一括紐付けすると総件数が減り、この状態になる。
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
     * 紐付け状態は song_id を根拠にすること
     *
     * マッピングが無くても song_id があれば「紐付け済み」と表示する。
     * bulkLinkAutoMatched() の対象条件が song_id IS NULL なので、
     * 「未紐付け」を一括紐付けの対象集合と一致させるための意図的な選択。
     * 判定元を timestamp_song_mappings に付け替えるとこの一致が崩れる。
     */
    public function test_status_is_based_on_song_id_not_mapping(): void
    {
        $this->actingAs(User::factory()->create());

        // マッピングは作らない
        $song = Song::factory()->create(['title' => 'マスタの曲名', 'artist' => 'マスタのアーティスト']);
        $this->createDecomposition('マッピングが無いテキスト', ['song_id' => $song->id]);

        $this->get(route('songs.decompose.linked'))
            ->assertOk()
            ->assertSee('data-status="linked"', false);
    }

    /**
     * 正規化画面での修正が反映されないことが画面に書かれていること
     *
     * この画面は正規化画面へ誘導しているが、そこでの解除・付け替えは
     * timestamp_song_mappings しか更新せず一覧には反映されない。
     * 注記が無いと、案内どおり直した利用者に古い紐付けを見せ続ける。
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
     * 状態列の可視ラベルが出ていること
     *
     * data-status 属性と @if が別々に条件を書いているため、
     * 属性だけを見ていると人間が読むラベルの入れ替わりを検知できない。
     */
    public function test_shows_visible_status_labels(): void
    {
        $this->actingAs(User::factory()->create());

        $song = Song::factory()->create();
        $this->createDecomposition('紐付け済みのテキスト', ['song_id' => $song->id]);

        $this->get(route('songs.decompose.linked'))
            ->assertOk()
            ->assertSee('>紐付け済み</span>', false);

        $this->createDecomposition('未紐付けのテキスト');

        $this->get(route('songs.decompose.linked'))
            ->assertOk()
            ->assertSee('>未紐付け</span>', false);
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
