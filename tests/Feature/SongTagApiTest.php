<?php

namespace Tests\Feature;

use App\Models\Song;
use App\Models\SongTag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SongTagApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'super_admin']);
    }

    public function test_list_tags(): void
    {
        $song = Song::factory()->create();
        SongTag::factory()->create(['song_id' => $song->id, 'value' => 'タグA']);
        SongTag::factory()->create(['song_id' => $song->id, 'value' => 'タグB']);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/songs/{$song->id}/tags");

        $response->assertOk()
            ->assertJsonCount(2, 'tags')
            ->assertJsonPath('tags.0.value', 'タグA');
    }

    public function test_add_tag(): void
    {
        $song = Song::factory()->create();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/songs/{$song->id}/tags", ['value' => '新しいタグ']);

        $response->assertStatus(201)
            ->assertJsonPath('tag.value', '新しいタグ');

        $this->assertDatabaseHas('song_tags', [
            'song_id' => $song->id,
            'value' => '新しいタグ',
        ]);
    }

    public function test_add_tag_validation(): void
    {
        $song = Song::factory()->create();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/songs/{$song->id}/tags", ['value' => '']);

        $response->assertStatus(422);
    }

    public function test_delete_tag(): void
    {
        $song = Song::factory()->create();
        $tag = SongTag::factory()->create(['song_id' => $song->id, 'value' => '削除対象']);

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/songs/{$song->id}/tags/{$tag->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('song_tags', ['id' => $tag->id]);
    }

    public function test_delete_tag_of_different_song_returns_404(): void
    {
        $song1 = Song::factory()->create();
        $song2 = Song::factory()->create();
        $tag = SongTag::factory()->create(['song_id' => $song2->id, 'value' => '他の曲のタグ']);

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/songs/{$song1->id}/tags/{$tag->id}");

        $response->assertStatus(404);
    }

    public function test_add_tag_marks_review_status_safe(): void
    {
        $song = Song::factory()->create(['review_status' => null]);

        $this->actingAs($this->admin)
            ->postJson("/api/songs/{$song->id}/tags", ['value' => 'テスト']);

        $this->assertEquals('safe', $song->fresh()->review_status);
    }

    public function test_delete_tag_marks_review_status_safe(): void
    {
        $song = Song::factory()->create(['review_status' => null]);
        $tag = SongTag::factory()->create(['song_id' => $song->id, 'value' => '削除']);

        $this->actingAs($this->admin)
            ->deleteJson("/api/songs/{$song->id}/tags/{$tag->id}");

        $this->assertEquals('safe', $song->fresh()->review_status);
    }

    public function test_unauthenticated_user_cannot_access(): void
    {
        $song = Song::factory()->create();

        $this->getJson("/api/songs/{$song->id}/tags")->assertStatus(401);
    }
}
