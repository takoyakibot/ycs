<?php

namespace Tests\Unit\Helpers;

use App\Helpers\ArtistTagHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ArtistTagHelperTest extends TestCase
{
    #[DataProvider('splitProvider')]
    public function test_split_artist_to_tags(string $input, array $expected): void
    {
        $this->assertSame($expected, ArtistTagHelper::splitArtistToTags($input));
    }

    public static function splitProvider(): array
    {
        return [
            'empty' => ['', []],
            'single' => ['アーティスト', ['アーティスト']],
            'slash' => ['A / B', ['A', 'B']],
            'fullwidth slash' => ['A／B', ['A', 'B']],
            'comma' => ['A, B', ['A', 'B']],
            'japanese comma' => ['A、B', ['A', 'B']],
            'ampersand' => ['A & B', ['A', 'B']],
            'fullwidth ampersand' => ['A＆B', ['A', 'B']],
            'cross mark' => ['A×B', ['A', 'B']],
            'x separator' => ['A x B', ['A', 'B']],
            'feat' => ['A feat. B', ['A', 'B']],
            'feat without dot' => ['A feat B', ['A', 'B']],
            'ft' => ['A ft. B', ['A', 'B']],
            'multiple separators' => ['A / B feat. C', ['A', 'B', 'C']],
            'trims whitespace' => [' A / B ', ['A', 'B']],
            'filters empty' => ['A//B', ['A', 'B']],
        ];
    }
}
