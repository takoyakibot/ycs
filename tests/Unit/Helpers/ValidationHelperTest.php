<?php

namespace Tests\Unit\Helpers;

use App\Helpers\ValidationHelper;
use PHPUnit\Framework\TestCase;

class ValidationHelperTest extends TestCase
{
    /**
     * 有効なSpotify Track IDの検証テスト
     */
    public function test_valid_spotify_track_id(): void
    {
        // 22文字の英数字
        $validId = '1234567890abcdefghij12';
        $this->assertEquals($validId, ValidationHelper::validateSpotifyTrackId($validId));
        $this->assertTrue(ValidationHelper::isValidSpotifyTrackId($validId));

        // 大文字小文字混合
        $mixedCaseId = 'AbCdEfGhIj1234567890AB';
        $this->assertEquals($mixedCaseId, ValidationHelper::validateSpotifyTrackId($mixedCaseId));
        $this->assertTrue(ValidationHelper::isValidSpotifyTrackId($mixedCaseId));
    }

    /**
     * 無効なSpotify Track IDの検証テスト
     */
    public function test_invalid_spotify_track_id(): void
    {
        // null
        $this->assertNull(ValidationHelper::validateSpotifyTrackId(null));
        $this->assertFalse(ValidationHelper::isValidSpotifyTrackId(null));

        // 空文字
        $this->assertNull(ValidationHelper::validateSpotifyTrackId(''));
        $this->assertFalse(ValidationHelper::isValidSpotifyTrackId(''));

        // 21文字（短い）
        $this->assertNull(ValidationHelper::validateSpotifyTrackId('123456789012345678901'));
        $this->assertFalse(ValidationHelper::isValidSpotifyTrackId('123456789012345678901'));

        // 23文字（長い）
        $this->assertNull(ValidationHelper::validateSpotifyTrackId('12345678901234567890123'));
        $this->assertFalse(ValidationHelper::isValidSpotifyTrackId('12345678901234567890123'));

        // 特殊文字を含む
        $this->assertNull(ValidationHelper::validateSpotifyTrackId('1234567890abcdef@#$%12'));
        $this->assertFalse(ValidationHelper::isValidSpotifyTrackId('1234567890abcdef@#$%12'));
    }

    /**
     * 有効なYouTube Video IDの検証テスト
     */
    public function test_valid_youtube_video_id(): void
    {
        // 11文字の英数字
        $validId = 'dQw4w9WgXcQ';
        $this->assertEquals($validId, ValidationHelper::validateYouTubeVideoId($validId));
        $this->assertTrue(ValidationHelper::isValidYouTubeVideoId($validId));

        // ハイフンを含む
        $withHyphen = 'dQw4w9Wg-cQ';
        $this->assertEquals($withHyphen, ValidationHelper::validateYouTubeVideoId($withHyphen));
        $this->assertTrue(ValidationHelper::isValidYouTubeVideoId($withHyphen));

        // アンダースコアを含む
        $withUnderscore = 'dQw4w9Wg_cQ';
        $this->assertEquals($withUnderscore, ValidationHelper::validateYouTubeVideoId($withUnderscore));
        $this->assertTrue(ValidationHelper::isValidYouTubeVideoId($withUnderscore));
    }

    /**
     * 無効なYouTube Video IDの検証テスト
     */
    public function test_invalid_youtube_video_id(): void
    {
        // null
        $this->assertNull(ValidationHelper::validateYouTubeVideoId(null));
        $this->assertFalse(ValidationHelper::isValidYouTubeVideoId(null));

        // 空文字
        $this->assertNull(ValidationHelper::validateYouTubeVideoId(''));
        $this->assertFalse(ValidationHelper::isValidYouTubeVideoId(''));

        // 10文字（短い）
        $this->assertNull(ValidationHelper::validateYouTubeVideoId('dQw4w9WgXc'));
        $this->assertFalse(ValidationHelper::isValidYouTubeVideoId('dQw4w9WgXc'));

        // 12文字（長い）
        $this->assertNull(ValidationHelper::validateYouTubeVideoId('dQw4w9WgXcQQ'));
        $this->assertFalse(ValidationHelper::isValidYouTubeVideoId('dQw4w9WgXcQQ'));

        // 不正な文字を含む
        $this->assertNull(ValidationHelper::validateYouTubeVideoId('dQw4w9Wg@cQ'));
        $this->assertFalse(ValidationHelper::isValidYouTubeVideoId('dQw4w9Wg@cQ'));
    }

    /**
     * parseBoolean メソッドのテスト
     */
    public function test_parse_boolean(): void
    {
        // boolean値
        $this->assertTrue(ValidationHelper::parseBoolean(true));
        $this->assertFalse(ValidationHelper::parseBoolean(false));

        // 文字列
        $this->assertTrue(ValidationHelper::parseBoolean('true'));
        $this->assertTrue(ValidationHelper::parseBoolean('1'));
        $this->assertTrue(ValidationHelper::parseBoolean('yes'));
        $this->assertTrue(ValidationHelper::parseBoolean('on'));
        $this->assertFalse(ValidationHelper::parseBoolean('false'));
        $this->assertFalse(ValidationHelper::parseBoolean('0'));
        $this->assertFalse(ValidationHelper::parseBoolean('no'));
        $this->assertFalse(ValidationHelper::parseBoolean('off'));

        // 数値
        $this->assertTrue(ValidationHelper::parseBoolean(1));
        $this->assertFalse(ValidationHelper::parseBoolean(0));

        // null/空文字（デフォルト値を返す）
        $this->assertFalse(ValidationHelper::parseBoolean(null));
        $this->assertTrue(ValidationHelper::parseBoolean(null, true));
        $this->assertFalse(ValidationHelper::parseBoolean('', false));

        // 不正な値（デフォルト値を返す）
        $this->assertFalse(ValidationHelper::parseBoolean('invalid'));
        $this->assertTrue(ValidationHelper::parseBoolean('invalid', true));
    }
}
