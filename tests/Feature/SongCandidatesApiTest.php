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
