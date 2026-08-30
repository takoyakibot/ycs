<?php

namespace Tests\Unit\Models;

use App\Models\Song;
use App\Models\TimestampSongMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimestampSongMappingConfirmedTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_confirmed_returns_manual_linked_only(): void
    {
        $song = Song::factory()->create();

        // 手動確定
        TimestampSongMapping::factory()->withSong($song)->create([
            'normalized_text' => 'manual',
            'is_manual' => true,
            'status' => TimestampSongMapping::STATUS_LINKED,
        ]);
        // 自動紐付け未レビュー
        TimestampSongMapping::factory()->withSong($song)->create([
            'normalized_text' => 'auto',
            'is_manual' => false,
            'status' => TimestampSongMapping::STATUS_LINKED,
        ]);
        // 保留
        TimestampSongMapping::factory()->create([
            'normalized_text' => 'pending',
            'status' => TimestampSongMapping::STATUS_PENDING,
        ]);

        $confirmed = TimestampSongMapping::confirmed()->get();

        $this->assertCount(1, $confirmed);
        $this->assertEquals('manual', $confirmed->first()->normalized_text);
    }

    public function test_scope_auto_linked_unreviewed_returns_auto_linked_only(): void
    {
        $song = Song::factory()->create();

        TimestampSongMapping::factory()->withSong($song)->create([
            'normalized_text' => 'manual',
            'is_manual' => true,
            'status' => TimestampSongMapping::STATUS_LINKED,
        ]);
        TimestampSongMapping::factory()->withSong($song)->create([
            'normalized_text' => 'auto',
            'is_manual' => false,
            'status' => TimestampSongMapping::STATUS_LINKED,
        ]);

        $unreviewed = TimestampSongMapping::autoLinkedUnreviewed()->get();

        $this->assertCount(1, $unreviewed);
        $this->assertEquals('auto', $unreviewed->first()->normalized_text);
    }

    public function test_is_confirmed_instance_method(): void
    {
        $mapping = new TimestampSongMapping([
            'status' => TimestampSongMapping::STATUS_LINKED,
            'is_manual' => true,
        ]);
        $this->assertTrue($mapping->isConfirmed());

        $mapping->is_manual = false;
        $this->assertFalse($mapping->isConfirmed());
    }

    public function test_is_auto_linked_unreviewed_instance_method(): void
    {
        $mapping = new TimestampSongMapping([
            'status' => TimestampSongMapping::STATUS_LINKED,
            'is_manual' => false,
        ]);
        $this->assertTrue($mapping->isAutoLinkedUnreviewed());

        $mapping->is_manual = true;
        $this->assertFalse($mapping->isAutoLinkedUnreviewed());
    }

    public function test_confirmed_join_conditions_returns_expected_keys(): void
    {
        $conditions = TimestampSongMapping::confirmedJoinConditions();

        $this->assertArrayHasKey('timestamp_song_mappings.status', $conditions);
        $this->assertArrayHasKey('timestamp_song_mappings.is_manual', $conditions);
        $this->assertEquals(TimestampSongMapping::STATUS_LINKED, $conditions['timestamp_song_mappings.status']);
        $this->assertTrue($conditions['timestamp_song_mappings.is_manual']);
    }
}
