<?php

namespace Tests\Unit\Services;

use App\Services\TimestampExtractorService;
use Tests\TestCase;

class TimestampExtractorServiceTest extends TestCase
{
    private TimestampExtractorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TimestampExtractorService;
    }

    /**
     * 絵文字を含むタイムスタンプテキストが正しく抽出されることをテスト
     */
    public function test_extract_timestamps_preserves_emoji(): void
    {
        $description = "0:00 🎵 オープニング\n1:30 🔥 アーティスト / 曲名 🎶\n3:00 ⭐ エンディング";
        $results = $this->service->extractTimestamps('test_video1', '1', $description, 'test_video1');

        $this->assertCount(3, $results);
        $this->assertEquals('🎵 オープニング', $results[0]['text']);
        $this->assertEquals('🔥 アーティスト / 曲名 🎶', $results[1]['text']);
        $this->assertEquals('⭐ エンディング', $results[2]['text']);
    }

    /**
     * 複合絵文字（ZWJ結合など）を含むテキストが正しく抽出されることをテスト
     */
    public function test_extract_timestamps_preserves_complex_emoji(): void
    {
        $description = "0:00 👋🏻 あいさつ\n1:00 🏳️‍🌈 テスト";
        $results = $this->service->extractTimestamps('test_video2', '1', $description, 'test_video2');

        $this->assertCount(2, $results);
        $this->assertEquals('👋🏻 あいさつ', $results[0]['text']);
        $this->assertEquals('🏳️‍🌈 テスト', $results[1]['text']);
    }
}
