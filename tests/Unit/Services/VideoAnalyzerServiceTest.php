<?php

namespace Tests\Unit\Services;

use App\Services\VideoAnalyzerService;
use PHPUnit\Framework\TestCase;

class VideoAnalyzerServiceTest extends TestCase
{
    protected VideoAnalyzerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new VideoAnalyzerService;
    }

    public function test_is_singing_stream_detects_japanese(): void
    {
        $this->assertTrue($this->service->isSingingStream('【歌枠】夜の歌枠配信'));
        $this->assertTrue($this->service->isSingingStream('カラオケ配信'));
    }

    public function test_is_singing_stream_detects_english(): void
    {
        $this->assertTrue($this->service->isSingingStream('Singing Stream'));
        $this->assertTrue($this->service->isSingingStream('karaoke night'));
    }

    public function test_is_singing_stream_returns_false_for_normal(): void
    {
        $this->assertFalse($this->service->isSingingStream('普通の配信'));
        $this->assertFalse($this->service->isSingingStream('ゲーム実況'));
    }

    public function test_is_cover_song_detects_utatte_mita(): void
    {
        $this->assertTrue($this->service->isCoverSong('夜に駆ける 歌ってみた'));
        $this->assertTrue($this->service->isCoverSong('【歌ってみた】Lemon'));
    }

    public function test_is_cover_song_detects_cover(): void
    {
        $this->assertTrue($this->service->isCoverSong('Lemon - Cover'));
        $this->assertTrue($this->service->isCoverSong('YOASOBI cover'));
    }

    public function test_is_cover_song_detects_music_video(): void
    {
        $this->assertTrue($this->service->isCoverSong('夜に駆ける - Music Video'));
        $this->assertTrue($this->service->isCoverSong('【Music Video】Lemon'));
        $this->assertTrue($this->service->isCoverSong('YOASOBI music video'));
    }

    public function test_is_cover_song_detects_katakana_cover(): void
    {
        $this->assertTrue($this->service->isCoverSong('夜に駆ける カバー'));
    }

    public function test_is_cover_song_returns_false_for_normal(): void
    {
        $this->assertFalse($this->service->isCoverSong('普通の配信'));
        $this->assertFalse($this->service->isCoverSong('歌枠配信'));
    }

    public function test_singing_stream_and_cover_are_mutually_exclusive(): void
    {
        // 歌枠とカバー曲は別カテゴリ
        $singingTitle = '【歌枠】歌ってみる配信';
        $coverTitle = '夜に駆ける 歌ってみた';

        // 歌枠タイトルは歌枠として検出されるが、カバー曲ではない
        $this->assertTrue($this->service->isSingingStream($singingTitle));
        $this->assertFalse($this->service->isCoverSong($singingTitle));

        // カバー曲タイトルはカバー曲として検出されるが、歌枠ではない
        $this->assertFalse($this->service->isSingingStream($coverTitle));
        $this->assertTrue($this->service->isCoverSong($coverTitle));
    }
}
