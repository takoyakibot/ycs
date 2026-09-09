<?php

namespace Tests\Feature;

use App\Helpers\TextNormalizer;
use App\Models\Archive;
use App\Models\TimestampDecomposition;
use App\Models\TsItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DecomposeVideoPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_next_includes_video_id_and_ts_num(): void
    {
        $archive = Archive::factory()->create(['is_display' => true]);
        $text = '曲名/アーティスト';
        $normalizedText = TextNormalizer::normalize($text);

        TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => $text,
            'ts_num' => 120,
            'is_display' => true,
        ]);

        TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => $normalizedText,
            'original_text' => $text,
            'parts' => ['曲名', 'アーティスト'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ]);

        $response = $this->getJson('/api/songs/decompose/next');

        $response->assertOk();
        $response->assertJsonPath('item.video_id', $archive->video_id);
        $response->assertJsonPath('item.ts_num', 120);
        $response->assertJsonPath('item.archive_title', $archive->title);
    }

    public function test_next_returns_no_item_when_ts_item_hidden(): void
    {
        $text = '曲名/アーティスト';
        $archive = Archive::factory()->create(['is_display' => true]);

        TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => $text,
            'is_display' => false,
        ]);

        TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize($text),
            'original_text' => $text,
            'parts' => ['曲名', 'アーティスト'],
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'confidence' => 0.5,
        ]);

        $response = $this->getJson('/api/songs/decompose/next');

        $response->assertOk();
        $response->assertJsonPath('item', null);
    }
}
