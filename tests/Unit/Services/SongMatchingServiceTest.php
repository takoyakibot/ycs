<?php

namespace Tests\Unit\Services;

use App\Models\Song;
use App\Services\SongMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongMatchingServiceTest extends TestCase
{
    use RefreshDatabase;

    private SongMatchingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SongMatchingService::class);
    }

    private function createSong(string $title, string $artist): Song
    {
        return Song::factory()->create(['title' => $title, 'artist' => $artist]);
    }

    #[Test]
    public function 装飾のないテキストは完全一致として最高の信頼度になる(): void
    {
        $song = $this->createSong('シャルル', 'バルーン');

        $candidates = $this->service->findCandidates('シャルル');

        $this->assertCount(1, $candidates);
        $this->assertSame($song->id, $candidates[0]['song_id']);
        $this->assertSame(SongMatchingService::CONFIDENCE_EXACT, $candidates[0]['confidence']);
    }

    /**
     * 除去パターンを1つも定義せずに装飾を無視できることを確認する。
     */
    #[Test]
    #[DataProvider('decoratedTextProvider')]
    public function 装飾付きのテキストでも楽曲マスタに紐付く(string $text): void
    {
        $song = $this->createSong('ロキ', 'みきとP');

        $match = $this->service->findBestMatch($text);

        $this->assertNotNull($match, sprintf('「%s」が照合できていない', $text));
        $this->assertSame($song->id, $match['song_id']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function decoratedTextProvider(): array
    {
        return [
            '記号の前置' => ['♪ロキ / みきとP'],
            '絵文字の後置' => ['ロキ / みきとP🎤'],
            '隅付き括弧' => ['【ロキ / みきとP】'],
            '曲番号（半角数字）' => ['01.ロキ/みきとP'],
            '曲番号（漢数字）' => ['零一 ロキ / みきとP'],
            '曲番号（丸数字）' => ['②ロキ / みきとP'],
            '曲番号（日本語）' => ['1曲目 ロキ　みきとP'],
            '時間範囲の混入' => ['0:12:34～0:16:02 ロキ / みきとP'],
            '全角スペース区切り' => ['ロキ　みきとP'],
            '括弧でのアーティスト表記' => ['ロキ（みきとP）'],
            'カバー表記付き' => ['ロキ / みきとP (cover)'],
            'アーティストが先' => ['みきとP / ロキ'],
            '星記号で囲む' => ['★☆ロキ☆★ みきとP'],
        ];
    }

    #[Test]
    public function アーティスト表記が異なっても楽曲マスタに紐付く(): void
    {
        // マスタは作曲者名で登録されているが、タイムスタンプ側はボカロ名を書いている
        $song = $this->createSong('ロキ', 'みきとP');

        $match = $this->service->findBestMatch('ロキ / 鏡音リン');

        // タイトルが十分に短いためアーティスト不一致では自動紐付けされないが、
        // 候補としては提示される
        $this->assertNull($match);

        $candidates = $this->service->findCandidates('ロキ / 鏡音リン');
        $this->assertNotEmpty($candidates);
        $this->assertSame($song->id, $candidates[0]['song_id']);
    }

    #[Test]
    public function アーティスト名の連結表記でも加点される(): void
    {
        $song = $this->createSong('ロキ', 'みきとP feat.鏡音リン');

        // マスタ側が連結表記、タイムスタンプ側は片方だけ
        $match = $this->service->findBestMatch('ロキ / 鏡音リン');

        $this->assertNotNull($match);
        $this->assertSame($song->id, $match['song_id']);
        $this->assertTrue($match['artist_hit']);
    }

    #[Test]
    public function 長いタイトルはアーティストが無くても候補になる(): void
    {
        $song = $this->createSong('愛して愛して愛して', 'きくお');

        $candidates = $this->service->findCandidates('♪愛して愛して愛して🎤');

        $this->assertNotEmpty($candidates);
        $this->assertSame($song->id, $candidates[0]['song_id']);
        $this->assertSame(SongMatchingService::CONFIDENCE_EXACT, $candidates[0]['confidence']);
    }

    #[Test]
    public function 短いタイトルの偶然の一致は自動紐付けされない(): void
    {
        $this->createSong('夜', 'ヨルシカ');

        $match = $this->service->findBestMatch('夜に駆ける / YOASOBI');

        $this->assertNull($match, '短いタイトルの部分一致で自動紐付けされてはいけない');
    }

    #[Test]
    public function 一文字のタイトルは照合対象にならない(): void
    {
        $this->createSong('あ', 'テスト');

        $candidates = $this->service->findCandidates('あいうえお / だれか');

        $this->assertEmpty($candidates);
    }

    #[Test]
    public function 同じ信頼度で複数の楽曲が一致する場合は自動紐付けしない(): void
    {
        // 同名タイトルで別アーティストの楽曲が2件ある状態
        $this->createSong('カタルシス', 'アーティストA');
        $this->createSong('カタルシス', 'アーティストB');

        $match = $this->service->findBestMatch('カタルシス');

        $this->assertNull($match, '曖昧な場合は候補提示に留めるべき');

        $candidates = $this->service->findCandidates('カタルシス');
        $this->assertCount(2, $candidates);
    }

    #[Test]
    public function より長く一致する楽曲が優先される(): void
    {
        $this->createSong('ロキ', 'みきとP');
        $longer = $this->createSong('ロキロキ', 'べつのひと');

        $candidates = $this->service->findCandidates('ロキロキ');

        $this->assertSame($longer->id, $candidates[0]['song_id']);
    }

    #[Test]
    public function 一致する楽曲がない場合は空になる(): void
    {
        $this->createSong('シャルル', 'バルーン');

        $this->assertEmpty($this->service->findCandidates('全く関係のない文字列'));
        $this->assertNull($this->service->findBestMatch('全く関係のない文字列'));
    }

    #[Test]
    public function 照合できない空のテキストは空を返す(): void
    {
        $this->createSong('シャルル', 'バルーン');

        $this->assertEmpty($this->service->findCandidates(''));
        $this->assertEmpty($this->service->findCandidates('♪♪♪'));
    }

    #[Test]
    public function 候補数は上限で打ち切られる(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->createSong('カタルシス', 'アーティスト'.$i);
        }

        $candidates = $this->service->findCandidates('カタルシス', 3);

        $this->assertCount(3, $candidates);
    }

    #[Test]
    public function 楽曲マスタ更新後はインデックスを破棄して再照合できる(): void
    {
        $this->assertEmpty($this->service->findCandidates('シャルル'));

        $song = $this->createSong('シャルル', 'バルーン');
        $this->service->flushIndex();

        $candidates = $this->service->findCandidates('シャルル');
        $this->assertCount(1, $candidates);
        $this->assertSame($song->id, $candidates[0]['song_id']);
    }
}
