<?php

namespace Tests\Unit\Services;

use App\Services\TimestampDecompositionService;
use ReflectionMethod;
use Tests\TestCase;

class JoinPartsWithOriginalSeparatorsTest extends TestCase
{
    private TimestampDecompositionService $service;

    private ReflectionMethod $method;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TimestampDecompositionService::class);
        $this->method = new ReflectionMethod($this->service, 'joinPartsWithOriginalSeparators');
        $this->method->setAccessible(true);
    }

    private function join(string $original, array $parts, array $indices): string
    {
        return $this->method->invoke($this->service, $original, $parts, $indices);
    }

    public function test_trailing_hyphen_preserved_in_title(): void
    {
        $original = 'STEEL-鉄血の絆- / TRUE';
        $parts = ['STEEL', '鉄血の絆', 'TRUE'];

        $title = $this->join($original, $parts, [0, 1]);
        $artist = $this->join($original, $parts, [2]);

        $this->assertEquals('STEEL-鉄血の絆-', $title);
        $this->assertEquals('TRUE', $artist);
    }

    public function test_standard_slash_separation(): void
    {
        $original = '夜に駆ける / YOASOBI';
        $parts = ['夜に駆ける', 'YOASOBI'];

        $title = $this->join($original, $parts, [0]);
        $artist = $this->join($original, $parts, [1]);

        $this->assertEquals('夜に駆ける', $title);
        $this->assertEquals('YOASOBI', $artist);
    }
}
