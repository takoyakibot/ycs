<?php

namespace Tests\Unit\Services;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\TsItem;
use App\Services\DuplicateCommentDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DuplicateCommentDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected DuplicateCommentDetectionService $service;

    protected string $videoId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DuplicateCommentDetectionService::class);

        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);
        $this->videoId = $archive->video_id;
    }

    private function createCommentTs(array $overrides = []): TsItem
    {
        return TsItem::factory()->fromComments()->create(array_merge(
            ['video_id' => $this->videoId],
            $overrides
        ));
    }

    public function test_detects_duplicate_within_threshold(): void
    {
        $this->createCommentTs(['ts_num' => 750, 'comment_id' => 'comment-aaa', 'text' => '夜に駆ける']);
        $this->createCommentTs(['ts_num' => 753, 'comment_id' => 'comment-bbb', 'text' => '夜に駆ける / YOASOBI']);

        $pairs = $this->service->detect($this->videoId);

        $this->assertCount(1, $pairs);
        $this->assertEquals(750, $pairs[0]->ts_num_a);
        $this->assertEquals(753, $pairs[0]->ts_num_b);
    }

    public function test_does_not_detect_beyond_threshold(): void
    {
        $this->createCommentTs(['ts_num' => 750, 'comment_id' => 'comment-aaa']);
        $this->createCommentTs(['ts_num' => 756, 'comment_id' => 'comment-bbb']);

        $pairs = $this->service->detect($this->videoId);

        $this->assertCount(0, $pairs);
    }

    public function test_detects_at_exact_threshold(): void
    {
        $this->createCommentTs(['ts_num' => 750, 'comment_id' => 'comment-aaa']);
        $this->createCommentTs(['ts_num' => 755, 'comment_id' => 'comment-bbb']);

        $pairs = $this->service->detect($this->videoId);

        $this->assertCount(1, $pairs);
    }

    public function test_does_not_detect_same_comment(): void
    {
        $this->createCommentTs(['ts_num' => 750, 'comment_id' => 'comment-aaa']);
        $this->createCommentTs(['ts_num' => 752, 'comment_id' => 'comment-aaa']);

        $pairs = $this->service->detect($this->videoId);

        $this->assertCount(0, $pairs);
    }

    public function test_does_not_detect_description_type(): void
    {
        TsItem::factory()->create([
            'video_id' => $this->videoId,
            'type' => '1',
            'ts_num' => 750,
            'comment_id' => null,
        ]);
        $this->createCommentTs(['ts_num' => 752, 'comment_id' => 'comment-bbb']);

        $pairs = $this->service->detect($this->videoId);

        $this->assertCount(0, $pairs);
    }

    public function test_does_not_detect_hidden_items(): void
    {
        $this->createCommentTs(['ts_num' => 750, 'comment_id' => 'comment-aaa', 'is_display' => 0]);
        $this->createCommentTs(['ts_num' => 752, 'comment_id' => 'comment-bbb']);

        $pairs = $this->service->detect($this->videoId);

        $this->assertCount(0, $pairs);
    }

    public function test_count_by_video_ids(): void
    {
        $this->createCommentTs(['ts_num' => 750, 'comment_id' => 'comment-aaa']);
        $this->createCommentTs(['ts_num' => 753, 'comment_id' => 'comment-bbb']);

        $channel2 = Channel::factory()->create();
        $archive2 = Archive::factory()->create(['channel_id' => $channel2->channel_id]);
        // 2つ目の動画には重複なし
        TsItem::factory()->fromComments()->create([
            'video_id' => $archive2->video_id,
            'ts_num' => 100,
            'comment_id' => 'comment-xxx',
        ]);

        $counts = $this->service->countByVideoIds([$this->videoId, $archive2->video_id]);

        $this->assertEquals(1, $counts[$this->videoId]);
        $this->assertArrayNotHasKey($archive2->video_id, $counts);
    }

    public function test_count_by_video_ids_empty(): void
    {
        $counts = $this->service->countByVideoIds([]);
        $this->assertEmpty($counts);
    }

    public function test_does_not_match_across_videos(): void
    {
        $this->createCommentTs(['ts_num' => 750, 'comment_id' => 'comment-aaa']);

        $channel2 = Channel::factory()->create();
        $archive2 = Archive::factory()->create(['channel_id' => $channel2->channel_id]);
        TsItem::factory()->fromComments()->create([
            'video_id' => $archive2->video_id,
            'ts_num' => 752,
            'comment_id' => 'comment-bbb',
        ]);

        $counts = $this->service->countByVideoIds([$this->videoId, $archive2->video_id]);

        $this->assertEmpty($counts);
    }
}
