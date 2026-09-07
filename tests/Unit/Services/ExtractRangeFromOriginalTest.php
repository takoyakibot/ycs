<?php

namespace Tests\Unit\Services;

use App\Services\TimestampDecompositionService;
use ReflectionMethod;
use Tests\TestCase;

class ExtractRangeFromOriginalTest extends TestCase
{
    private TimestampDecompositionService $service;

    private ReflectionMethod $method;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TimestampDecompositionService::class);
        $this->method = new ReflectionMethod($this->service, 'extractRangeFromOriginal');
        $this->method->setAccessible(true);
    }

    private function extract(string $original, array $parts, int $start, int $end): string
    {
        return $this->method->invoke($this->service, $original, $parts, $start, $end);
    }

    public function test_trailing_hyphen_preserved(): void
    {
        $original = 'STEEL-鉄血の絆- / TRUE';
        $parts = ['STEEL', '鉄血の絆', 'TRUE'];

        $result = $this->extract($original, $parts, 0, 1);
        $this->assertEquals('STEEL-鉄血の絆-', $result);
    }

    public function test_simple_slash_separator(): void
    {
        $original = 'Song / Artist';
        $parts = ['Song', 'Artist'];

        $result = $this->extract($original, $parts, 0, 0);
        $this->assertEquals('Song', $result);
    }

    public function test_simple_hyphen_separator(): void
    {
        $original = 'Song-Artist';
        $parts = ['Song', 'Artist'];

        $result = $this->extract($original, $parts, 0, 0);
        $this->assertEquals('Song', $result);
    }

    public function test_trailing_separator_with_space(): void
    {
        $original = 'Song- Artist';
        $parts = ['Song', 'Artist'];

        $result = $this->extract($original, $parts, 0, 0);
        $this->assertEquals('Song-', $result);
    }

    public function test_last_part_includes_trailing(): void
    {
        $original = '/ Song /';
        $parts = ['Song'];

        $result = $this->extract($original, $parts, 0, 0);
        $this->assertEquals('/ Song /', $result);
    }

    public function test_middle_range_preserves_internal_separators(): void
    {
        $original = 'A - B-C - D';
        $parts = ['A', 'B', 'C', 'D'];

        $result = $this->extract($original, $parts, 1, 2);
        $this->assertEquals('B-C', $result);
    }

    public function test_artist_part_not_affected(): void
    {
        $original = 'STEEL-鉄血の絆- / TRUE';
        $parts = ['STEEL', '鉄血の絆', 'TRUE'];

        $result = $this->extract($original, $parts, 2, 2);
        $this->assertEquals('TRUE', $result);
    }
}
