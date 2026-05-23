<?php

namespace Tests\Unit\Services\Highlights;

use App\Services\Highlights\HighlightCandidateExtractor;
use PHPUnit\Framework\TestCase;

class HighlightCandidateExtractorTest extends TestCase
{
    private HighlightCandidateExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new HighlightCandidateExtractor;
    }

    public function test_empty_inputs_return_empty(): void
    {
        $result = $this->extractor->extract(0, [], [], []);
        $this->assertSame([], $result);
    }

    public function test_zero_duration_returns_empty_even_with_data(): void
    {
        $result = $this->extractor->extract(0, [0.5, 0.5], [], [['offsetMs' => 0, 'message' => '草']]);
        $this->assertSame([], $result);
    }

    public function test_all_zero_volumes_no_chats_returns_empty(): void
    {
        // 300秒、全ウィンドウで音量0・コメントなし → 候補なし
        $duration = 300.0;
        $volumes = array_fill(0, 150, 0.0);
        $result = $this->extractor->extract($duration, $volumes, [], []);
        $this->assertSame([], $result);
    }

    public function test_uniform_volume_no_signal_returns_empty(): void
    {
        // 全ウィンドウで音量が等しい（σ=0）→ 候補なし
        $duration = 300.0;
        $volumes = array_fill(0, 150, 0.5);
        $result = $this->extractor->extract($duration, $volumes, [], []);
        $this->assertSame([], $result);
    }

    public function test_volume_spike_creates_candidate(): void
    {
        // 中盤(140-160秒)に強い音量ピーク
        $duration = 300.0;
        $volumes = array_fill(0, 150, 0.1);
        for ($i = 70; $i < 80; $i++) {
            $volumes[$i] = 1.0;
        }
        $result = $this->extractor->extract($duration, $volumes, [], []);

        $this->assertNotEmpty($result, '音量ピークがあれば候補が出ること');
        $found = array_filter($result, fn ($c) => $c['time'] >= 130 && $c['time'] <= 170);
        $this->assertNotEmpty($found, 'ピーク区間付近に候補があること');
    }

    public function test_chat_density_spike_creates_candidate(): void
    {
        $duration = 300.0;
        $volumes = array_fill(0, 150, 0.1);

        // 通常は30秒に1件、中盤(140-160秒)で40件密集
        $chats = [];
        for ($t = 0; $t < 300; $t += 30) {
            $chats[] = ['offsetMs' => $t * 1000, 'message' => 'hello'];
        }
        for ($i = 0; $i < 40; $i++) {
            $chats[] = ['offsetMs' => 140000 + $i * 500, 'message' => 'wow'];
        }

        $result = $this->extractor->extract($duration, $volumes, [], $chats);

        $this->assertNotEmpty($result);
        $found = array_filter($result, fn ($c) => $c['time'] >= 130 && $c['time'] <= 170);
        $this->assertNotEmpty($found, 'コメント密集区間に候補が出ること');
    }

    public function test_reaction_keywords_contribute_score(): void
    {
        $duration = 300.0;
        $volumes = array_fill(0, 150, 0.1);

        // リアクション語を1箇所に集中（密度スパイクは弱くてもキーワードで拾える）
        $chats = [];
        for ($i = 0; $i < 10; $i++) {
            $chats[] = ['offsetMs' => 100000 + $i * 200, 'message' => '草ｗｗｗ'];
        }

        $result = $this->extractor->extract($duration, $volumes, [], $chats);

        $this->assertNotEmpty($result);
        $first = $result[0];
        $this->assertGreaterThan(0, $first['keyword_score'], 'リアクション語のスコアが付くこと');
        $this->assertContains('草', $first['reaction_keywords']);
    }

    public function test_superchat_counted_with_higher_weight(): void
    {
        $duration = 300.0;
        $volumes = array_fill(0, 150, 0.1);

        // 通常コメントとSCを同じ区間に1件ずつ。SCは3倍ウェイトされる想定
        $chats = [
            // ベースライン: 全体に薄くコメント
            ['offsetMs' => 30000, 'message' => 'a'],
            ['offsetMs' => 60000, 'message' => 'b'],
            ['offsetMs' => 90000, 'message' => 'c'],
            ['offsetMs' => 120000, 'message' => 'd'],
            ['offsetMs' => 200000, 'message' => 'e'],
            // ターゲット区間にSC1件＋通常1件
            ['offsetMs' => 150000, 'message' => 'sc', 'isSuperchat' => true],
            ['offsetMs' => 151000, 'message' => 'normal'],
        ];

        $result = $this->extractor->extract($duration, $volumes, [], $chats);

        // SCがあるとカウントが膨らみ、しきい値を超える可能性が上がる
        $found = array_filter($result, fn ($c) => $c['time'] >= 140 && $c['time'] <= 160);
        $this->assertNotEmpty($found, 'SCを含む区間が候補化されること');
    }

    public function test_candidates_within_30sec_are_merged(): void
    {
        $duration = 300.0;
        $volumes = array_fill(0, 150, 0.1);
        // 100-110秒と120-130秒（25秒以内）の2つのピーク
        for ($i = 50; $i < 55; $i++) {
            $volumes[$i] = 1.0;
        }
        for ($i = 60; $i < 65; $i++) {
            $volumes[$i] = 1.0;
        }

        $result = $this->extractor->extract($duration, $volumes, [], []);

        $this->assertNotEmpty($result);
        // 近接ピークがマージされ、100付近を含む単一候補になる
        $merged = array_filter($result, fn ($c) => $c['time'] <= 110 && $c['end_time'] >= 120);
        $this->assertNotEmpty($merged, '近接ピークがマージされていること');
    }

    public function test_candidates_far_apart_are_not_merged(): void
    {
        $duration = 600.0;
        $volumes = array_fill(0, 300, 0.1);
        // 50秒と300秒に離れた2つのピーク
        for ($i = 25; $i < 30; $i++) {
            $volumes[$i] = 1.0;
        }
        for ($i = 150; $i < 155; $i++) {
            $volumes[$i] = 1.0;
        }

        $result = $this->extractor->extract($duration, $volumes, [], []);

        $this->assertGreaterThanOrEqual(2, count($result), '離れた2ピークは別候補として残ること');
    }

    public function test_candidates_are_sorted_by_time_ascending(): void
    {
        $duration = 600.0;
        $volumes = array_fill(0, 300, 0.1);
        for ($i = 25; $i < 30; $i++) {
            $volumes[$i] = 1.0;
        }
        for ($i = 150; $i < 155; $i++) {
            $volumes[$i] = 0.8;
        }

        $result = $this->extractor->extract($duration, $volumes, [], []);

        $times = array_column($result, 'time');
        $sorted = $times;
        sort($sorted);
        $this->assertSame($sorted, $times, '時刻昇順で返ること');
    }

    public function test_each_candidate_has_required_shape(): void
    {
        $duration = 300.0;
        $volumes = array_fill(0, 150, 0.1);
        for ($i = 70; $i < 80; $i++) {
            $volumes[$i] = 1.0;
        }

        $subtitles = [
            ['start' => 142.0, 'duration' => 3.0, 'text' => 'テスト字幕'],
        ];
        $chats = [
            ['offsetMs' => 142500, 'message' => 'コメント'],
        ];

        $result = $this->extractor->extract($duration, $volumes, $subtitles, $chats);
        $this->assertNotEmpty($result);

        foreach ($result as $c) {
            $this->assertArrayHasKey('time', $c);
            $this->assertArrayHasKey('end_time', $c);
            $this->assertArrayHasKey('score', $c);
            $this->assertArrayHasKey('volume_score', $c);
            $this->assertArrayHasKey('chat_score', $c);
            $this->assertArrayHasKey('keyword_score', $c);
            $this->assertArrayHasKey('chat_count', $c);
            $this->assertArrayHasKey('reaction_keywords', $c);
            $this->assertArrayHasKey('subtitles', $c);
            $this->assertArrayHasKey('chats', $c);
            $this->assertIsInt($c['time']);
            $this->assertIsArray($c['subtitles']);
            $this->assertIsArray($c['chats']);
        }
    }

    public function test_subtitles_and_chats_are_attached_to_candidates(): void
    {
        $duration = 300.0;
        $volumes = array_fill(0, 150, 0.1);
        for ($i = 70; $i < 80; $i++) {
            $volumes[$i] = 1.0;
        }

        $subtitles = [
            ['start' => 10.0, 'duration' => 2.0, 'text' => '範囲外'],
            ['start' => 142.0, 'duration' => 3.0, 'text' => '範囲内字幕'],
        ];
        $chats = [
            ['offsetMs' => 10000, 'message' => '範囲外'],
            ['offsetMs' => 142500, 'message' => '範囲内コメント'],
        ];

        $result = $this->extractor->extract($duration, $volumes, $subtitles, $chats);
        $candidate = array_values(array_filter($result, fn ($c) => $c['time'] >= 130 && $c['time'] <= 170))[0] ?? null;
        $this->assertNotNull($candidate);

        $subTexts = array_column($candidate['subtitles'], 'text');
        $chatMessages = array_column($candidate['chats'], 'message');
        $this->assertContains('範囲内字幕', $subTexts);
        $this->assertContains('範囲内コメント', $chatMessages);
        $this->assertNotContains('範囲外', $subTexts);
        $this->assertNotContains('範囲外', $chatMessages);
    }
}
