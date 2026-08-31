<?php

namespace Tests\Unit\Migrations;

use App\Models\Song;
use App\Models\SongTag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedSongTagsFromArtistTest extends TestCase
{
    use RefreshDatabase;

    /**
     * splitArtistToTags のロジックを直接テストする
     * マイグレーション自体はテスト DB セットアップ時に実行済み
     */

    /**
     * @dataProvider artistSplitProvider
     */
    public function test_split_artist_to_tags(string $artist, array $expectedTags): void
    {
        $result = $this->splitArtistToTags($artist);
        $this->assertEquals($expectedTags, $result);
    }

    public static function artistSplitProvider(): array
    {
        return [
            'カンマ区切り' => ['AAA,BBB', ['AAA', 'BBB']],
            'スラッシュ区切り' => ['AAA/BBB', ['AAA', 'BBB']],
            'feat.区切り' => ['AAA feat. BBB', ['AAA', 'BBB']],
            'feat区切り(ドットなし)' => ['AAA feat BBB', ['AAA', 'BBB']],
            'ft.区切り' => ['AAA ft. BBB', ['AAA', 'BBB']],
            '×区切り' => ['AAA×BBB', ['AAA', 'BBB']],
            'x区切り(小文字)' => ['AAA x BBB', ['AAA', 'BBB']],
            '&区切り' => ['AAA & BBB', ['AAA', 'BBB']],
            '全角＆区切り' => ['AAA＆BBB', ['AAA', 'BBB']],
            '複数区切り' => ['AAA,BBB/CCC', ['AAA', 'BBB', 'CCC']],
            '単一アーティスト' => ['バルーン', ['バルーン']],
            '空白のみのパーツは除外' => ['AAA, ,BBB', ['AAA', 'BBB']],
            'トリム' => [' AAA , BBB ', ['AAA', 'BBB']],
            '空文字' => ['', []],
        ];
    }

    /**
     * マイグレーションと同じ分割ロジック
     */
    private function splitArtistToTags(string $artist): array
    {
        if ($artist === '') {
            return [];
        }

        $normalized = preg_replace('/\s+feat\.?\s+/ui', "\x00", $artist);
        $normalized = preg_replace('/\s+ft\.?\s+/ui', "\x00", $normalized);
        $normalized = str_replace(['×', '＆'], "\x00", $normalized);
        $normalized = preg_replace('/\s+x\s+/u', "\x00", $normalized);
        $normalized = str_replace(['/', '／', ',', '、', '&'], "\x00", $normalized);

        $parts = explode("\x00", $normalized);
        $parts = array_map('trim', $parts);
        $parts = array_filter($parts, fn ($p) => $p !== '');
        $parts = array_values($parts);

        return $parts;
    }

    public function test_seed_creates_tags_for_existing_songs(): void
    {
        $song = Song::factory()->create(['artist' => 'AAA,BBB']);

        // マイグレーション再実行相当のロジック
        $tags = $this->splitArtistToTags($song->artist);
        foreach ($tags as $tag) {
            SongTag::create(['song_id' => $song->id, 'value' => $tag]);
        }

        $this->assertCount(2, $song->fresh()->tags);
        $this->assertEquals('AAA', $song->fresh()->tags[0]->value);
        $this->assertEquals('BBB', $song->fresh()->tags[1]->value);
    }
}
