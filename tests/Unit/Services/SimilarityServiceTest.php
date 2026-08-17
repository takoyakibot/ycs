<?php

namespace Tests\Unit\Services;

use App\Services\SimilarityService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SimilarityServiceTest extends TestCase
{
    private SimilarityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SimilarityService;
    }

    #[Test]
    public function 完全に同一の文字列は類似度1になる(): void
    {
        $this->assertSame(1.0, $this->service->calculateSimilarity('ロキ', 'ロキ'));
        $this->assertSame(1.0, $this->service->calculateSimilarity('pretender', 'pretender'));
    }

    #[Test]
    public function 空文字列は類似度0になる(): void
    {
        $this->assertSame(0.0, $this->service->calculateSimilarity('', 'ロキ'));
        $this->assertSame(0.0, $this->service->calculateSimilarity('ロキ', ''));
        $this->assertSame(0.0, $this->service->calculateSimilarity('', ''));
    }

    /**
     * バイト単位のlevenshtein()では日本語1文字の差が距離3として扱われ、
     * 短い文字列では類似度が0まで落ちてしまっていた。
     */
    #[Test]
    public function 日本語で1文字だけ異なる場合に文字数ベースの類似度になる(): void
    {
        // 2文字 → 4文字（記号2文字の追加）: 距離2 / 最大長4 = 0.5
        $similarity = $this->service->calculateSimilarity('ロキ', '「ロキ」');

        $this->assertSame(0.5, $similarity);
    }

    #[Test]
    public function 日本語の末尾1文字違いは高い類似度になる(): void
    {
        // 9文字 vs 10文字、距離1 → 1 - 1/10 = 0.9
        $similarity = $this->service->calculateSimilarity('愛して愛して愛して', '愛して愛して愛してる');

        $this->assertSame(0.9, $similarity);
    }

    #[Test]
    public function 記号が先頭に付与されただけの日本語は閾値07を超える(): void
    {
        $similarity = $this->service->calculateSimilarity('愛して愛して愛して', '♪愛して愛して愛して');

        $this->assertGreaterThanOrEqual(0.7, $similarity);
    }

    #[Test]
    public function 英数字の類似度は従来と同じ結果になる(): void
    {
        // 9文字中1文字違い
        $similarity = $this->service->calculateSimilarity('pretender', 'pretendar');

        $this->assertSame(round(1 - 1 / 9, 10), round($similarity, 10));
    }

    #[Test]
    public function 全く異なる文字列は類似度0になる(): void
    {
        $similarity = $this->service->calculateSimilarity('あいうえお', 'かきくけこ');

        $this->assertSame(0.0, $similarity);
    }

    #[Test]
    public function 戻り値は常に0から1の範囲に収まる(): void
    {
        $pairs = [
            ['ロキ', 'ロキ / みきとp'],
            ['a', 'あいうえおかきくけこさしすせそ'],
            ['愛して愛して愛して / きくお', 'き'],
        ];

        foreach ($pairs as [$a, $b]) {
            $similarity = $this->service->calculateSimilarity($a, $b);
            $this->assertGreaterThanOrEqual(0.0, $similarity);
            $this->assertLessThanOrEqual(1.0, $similarity);
        }
    }

    #[Test]
    public function 極端に長い文字列でも例外なく計算できる(): void
    {
        $long = str_repeat('あ', 1000);
        $other = str_repeat('あ', 999).'い';

        $similarity = $this->service->calculateSimilarity($long, $other);

        // 先頭255文字で打ち切られるため、差分が範囲外となり完全一致扱いになる
        $this->assertSame(1.0, $similarity);
    }

    #[Test]
    #[DataProvider('levenshteinProvider')]
    public function 文字単位のレーベンシュタイン距離を計算する(string $a, string $b, int $expected): void
    {
        $this->assertSame($expected, SimilarityService::levenshtein($a, $b));
    }

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function levenshteinProvider(): array
    {
        return [
            '同一' => ['ロキ', 'ロキ', 0],
            '片方が空' => ['', 'ロキ', 2],
            '両方空' => ['', '', 0],
            '日本語の挿入2文字' => ['ロキ', '「ロキ」', 2],
            '日本語の置換1文字' => ['ロキ', 'ロク', 1],
            '英字の置換1文字' => ['kitten', 'sitten', 1],
            '英字の複合' => ['kitten', 'sitting', 3],
            '絵文字の追加' => ['ロキ', 'ロキ🎤', 1],
        ];
    }
}
