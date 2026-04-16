<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\ChannelExcludedWord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class ManageCoverSongPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_preserves_wave_dash_character(): void
    {
        $user = User::factory()->create();
        $channel = Channel::factory()->create(['user_id' => $user->id]);

        // 波ダッシュ（U+301C）を含むカバー曲タイトル
        Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'title' => 'フクロウ〜フクロウが知らせる客が来たと〜/《天和うる Cover》',
        ]);

        // 除外ワードを登録
        ChannelExcludedWord::create([
            'channel_id' => $channel->channel_id,
            'word' => '/《天和うる Cover》',
        ]);

        $cryptHandle = Crypt::encryptString($channel->handle);

        $response = $this->actingAs($user)
            ->getJson("/api/manage/channels/{$cryptHandle}/cover-songs/preview");

        $response->assertOk();

        $previews = $response->json();
        $this->assertCount(1, $previews);

        // 波ダッシュ（〜）が ? に化けていないことを確認
        $this->assertStringContainsString('〜', $previews[0]['extracted_text']);
        $this->assertStringNotContainsString('?', $previews[0]['extracted_text']);
        $this->assertEquals(
            'フクロウ〜フクロウが知らせる客が来たと〜',
            $previews[0]['extracted_text']
        );
    }

    public function test_preview_handles_fullwidth_tilde(): void
    {
        $user = User::factory()->create();
        $channel = Channel::factory()->create(['user_id' => $user->id]);

        // 全角チルダ（U+FF5E）を含むカバー曲タイトル
        Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'title' => 'テスト～テスト【歌ってみた】',
        ]);

        $cryptHandle = Crypt::encryptString($channel->handle);

        $response = $this->actingAs($user)
            ->getJson("/api/manage/channels/{$cryptHandle}/cover-songs/preview");

        $response->assertOk();

        $previews = $response->json();
        $this->assertCount(1, $previews);

        // 全角チルダ（～）が保持されていること
        $this->assertStringContainsString('～', $previews[0]['extracted_text']);
    }

    public function test_preview_excludes_hidden_archives(): void
    {
        $user = User::factory()->create();
        $channel = Channel::factory()->create(['user_id' => $user->id]);

        // 表示対象のカバー曲
        Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'title' => '表示対象【歌ってみた】',
            'is_display' => 1,
        ]);

        // 非表示のカバー曲
        Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'title' => '非表示【Cover】',
            'is_display' => 0,
        ]);

        $cryptHandle = Crypt::encryptString($channel->handle);

        $response = $this->actingAs($user)
            ->getJson("/api/manage/channels/{$cryptHandle}/cover-songs/preview");

        $response->assertOk();

        $previews = $response->json();
        $this->assertCount(1, $previews);
        $this->assertStringContainsString('表示対象', $previews[0]['original_title']);
    }
}
