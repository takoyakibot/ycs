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
