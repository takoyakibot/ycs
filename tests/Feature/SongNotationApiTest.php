<?php

namespace Tests\Feature;

use App\Helpers\TextNormalizer;
use App\Models\Archive;
use App\Models\Channel;
use App\Models\Song;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SongNotationApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * レスポンス構造が正しいこと
     */
    public function test_notations_response_structure(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $song = Song::factory()->create(['title' => 'テスト曲', 'artist' => 'テストアーティスト']);
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id, 'is_display' => 1]);

        $text = 'テストアーティスト / テスト曲';
        TimestampSongMapping::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize($text),
            'song_id' => $song->id,
            'status' => TimestampSongMapping::STATUS_LINKED,
            'is_manual' => true,
            'is_not_song' => false,
        ]);
        TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => $text,
            'normalized_text' => TextNormalizer::normalize($text),
            'type' => '1',
            'is_display' => 1,
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/songs/{$song->id}/notations");

        $response->assertOk()
            ->assertJsonStructure([
                'song' => ['id', 'title', 'artist'],
                'notations' => [
                    '*' => ['text', 'frequency'],
                ],
                'total_timestamps',
                'excluded_count',
            ])
            ->assertJsonPath('song.id', $song->id)
            ->assertJsonPath('song.title', 'テスト曲')
            ->assertJsonPath('song.artist', 'テストアーティスト');
    }

    /**
     * 存在しない楽曲IDで404が返ること
     */
    public function test_notations_returns_404_for_nonexistent_song(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $nonexistentId = (string) Str::ulid();

        $response = $this->actingAs($admin)
            ->getJson("/api/songs/{$nonexistentId}/notations");

        $response->assertNotFound();
    }

    /**
     * 未認証で302リダイレクトが返ること
     */
    public function test_notations_redirects_unauthenticated(): void
    {
        $song = Song::factory()->create();

        $response = $this->get("/api/songs/{$song->id}/notations");

        $response->assertRedirect();
    }
}
