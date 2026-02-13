<?php

namespace App\Services;

use App\Helpers\TextNormalizer;
use App\Models\Song;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AutoLinkService
{
    protected SpotifyService $spotifyService;

    protected SongSearchService $songSearchService;

    /**
     * 1リクエストあたりの遅延時間（ミリ秒）
     */
    protected int $delayMs;

    /**
     * レート制限エラー時の待機時間（秒）
     */
    protected int $rateLimitWaitSeconds;

    public function __construct(SpotifyService $spotifyService, SongSearchService $songSearchService)
    {
        $this->spotifyService = $spotifyService;
        $this->songSearchService = $songSearchService;
        $this->delayMs = config('songs.auto_link.delay_ms', 500);
        $this->rateLimitWaitSeconds = config('songs.auto_link.rate_limit_wait_seconds', 60);
    }

    /**
     * 未紐付けのタイムスタンプを自動でSpotify検索し、トップ結果を紐付ける
     *
     * @param  int  $limit  処理件数上限
     * @param  callable|null  $onProgress  進捗コールバック function(string $message): void
     * @return array{processed: int, linked: int, pending: int, failed: int, skipped: int}
     */
    public function autoLinkUnlinkedTimestamps(int $limit = 100, ?callable $onProgress = null): array
    {
        $result = [
            'processed' => 0,
            'linked' => 0,
            'pending' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        // 未紐付けのタイムスタンプを取得（ユニークなnormalized_textのみ）
        $unlinkedTexts = $this->getUnlinkedTexts($limit);

        if (empty($unlinkedTexts)) {
            $onProgress && $onProgress('未紐付けのタイムスタンプが見つかりませんでした。');

            return $result;
        }

        $onProgress && $onProgress(sprintf('%d件の未紐付けテキストを処理します。', count($unlinkedTexts)));

        foreach ($unlinkedTexts as $index => $item) {
            $result['processed']++;

            try {
                $linkResult = $this->processAutoLink($item['text'], $item['normalized_text']);

                if ($linkResult === 'linked') {
                    $result['linked']++;
                    $onProgress && $onProgress(sprintf('[%d/%d] 紐付け成功: %s', $index + 1, count($unlinkedTexts), $item['text']));
                } elseif ($linkResult === 'pending') {
                    $result['pending']++;
                    $onProgress && $onProgress(sprintf('[%d/%d] 保留: %s', $index + 1, count($unlinkedTexts), $item['text']));
                } elseif ($linkResult === 'skipped') {
                    $result['skipped']++;
                    $onProgress && $onProgress(sprintf('[%d/%d] スキップ: %s', $index + 1, count($unlinkedTexts), $item['text']));
                } else {
                    $result['failed']++;
                    $onProgress && $onProgress(sprintf('[%d/%d] 検索結果なし: %s', $index + 1, count($unlinkedTexts), $item['text']));
                }

                // レート制限対策: 適切な遅延を入れる
                if ($index < count($unlinkedTexts) - 1) {
                    usleep($this->delayMs * 1000);
                }
            } catch (\Exception $e) {
                $result['failed']++;

                // レート制限エラーの場合は待機して続行
                if (strpos($e->getMessage(), 'レート制限') !== false) {
                    $this->log('warning', sprintf('レート制限に達しました。%d秒待機します。', $this->rateLimitWaitSeconds));
                    $onProgress && $onProgress(sprintf('レート制限に達しました。%d秒待機します...', $this->rateLimitWaitSeconds));
                    sleep($this->rateLimitWaitSeconds);

                    continue;
                }

                $this->log('error', sprintf('自動紐付けエラー: %s - %s', $item['text'], $e->getMessage()));
                $onProgress && $onProgress(sprintf('[%d/%d] エラー: %s - %s', $index + 1, count($unlinkedTexts), $item['text'], $e->getMessage()));
            }
        }

        return $result;
    }

    /**
     * 未紐付けのテキスト一覧を取得
     *
     * @param  int  $limit  取得件数上限
     * @return array<array{text: string, normalized_text: string}>
     */
    protected function getUnlinkedTexts(int $limit): array
    {
        return TsItem::select('ts_items.text', 'ts_items.normalized_text')
            ->leftJoin('timestamp_song_mappings', 'ts_items.normalized_text', '=', 'timestamp_song_mappings.normalized_text')
            ->whereNotNull('ts_items.text')
            ->where('ts_items.text', '!=', '')
            ->whereNotNull('ts_items.normalized_text')
            ->where('ts_items.is_display', 1)
            ->where('ts_items.type', '!=', '3') // 歌ってみた/カバー曲はノイズが多いため除外
            ->whereHas('archive', function ($q) {
                $q->where('is_display', 1);
            })
            ->whereNull('timestamp_song_mappings.id')
            ->groupBy('ts_items.normalized_text', 'ts_items.text')
            ->orderBy('ts_items.text', 'asc')
            ->limit($limit)
            ->get()
            ->map(fn ($item) => [
                'text' => $item->text,
                'normalized_text' => $item->normalized_text,
            ])
            ->toArray();
    }

    /**
     * 単一テキストの自動紐付け処理
     *
     * @return string 'linked'|'pending'|'skipped'|'not_found'
     */
    protected function processAutoLink(string $text, string $normalizedText): string
    {
        // Spotify検索実行
        $tracks = $this->spotifyService->searchWithAuth($text, 1);

        if (empty($tracks)) {
            return 'not_found';
        }

        $track = $tracks[0];
        $title = $track['name'];
        $artist = collect($track['artists'])->pluck('name')->join(', ');
        $spotifyTrackId = $track['id'];

        // 既存の楽曲マスタを検索（Spotify Track IDで）
        $existingSong = Song::where('spotify_track_id', $spotifyTrackId)->first();

        if ($existingSong) {
            // 既存の楽曲マスタを使用
            $this->createAutoLinkMapping($normalizedText, $existingSong->id);

            return 'linked';
        }

        // 正規化後のTitle + Artistで完全一致チェック
        $normalizedTitle = TextNormalizer::normalize($title);
        $normalizedArtist = TextNormalizer::normalize($artist);
        $exactMatch = $this->songSearchService->findExactMatch($normalizedTitle, $normalizedArtist, $title, $artist);

        if ($exactMatch) {
            // 既存の楽曲マスタを使用
            $this->createAutoLinkMapping($normalizedText, $exactMatch->id);

            return 'linked';
        }

        // 閾値を取得（0.0〜1.0の値）
        $similarityThreshold = config('songs.auto_link.similarity_threshold', 0.95);
        $pendingThreshold = config('songs.auto_link.pending_threshold', 0.85);

        // 類似度チェック（保留閾値以上の楽曲を検索）
        $similarSongs = $this->songSearchService->findSimilarSongs($normalizedTitle, $normalizedArtist, $pendingThreshold);

        if (count($similarSongs) > 0) {
            $bestMatch = $similarSongs[0];
            // findSimilarSongsはパーセント値（0〜100）を返すので、0〜1に変換して比較
            $bestSimilarity = $bestMatch['similarity'] / 100;

            if ($bestSimilarity >= $similarityThreshold) {
                // 自動紐付け閾値以上 → 紐付け
                $this->createAutoLinkMapping($normalizedText, $bestMatch['song']->id);

                return 'linked';
            } else {
                // 保留閾値以上、自動紐付け閾値未満 → 保留
                $this->createPendingMapping($normalizedText, $bestMatch['song']->id, $bestSimilarity);

                return 'pending';
            }
        }

        // 新規楽曲マスタを作成
        $song = $this->createSongFromSpotify($track);
        $this->createAutoLinkMapping($normalizedText, $song->id);

        return 'linked';
    }

    /**
     * Spotifyトラックデータから楽曲マスタを作成または取得
     *
     * ユニーク制約（title + artist）に基づいてfirstOrCreateを使用し、
     * 重複エラーを防止する
     */
    protected function createSongFromSpotify(array $track): Song
    {
        $title = $track['name'];
        $artist = collect($track['artists'])->pluck('name')->join(', ');

        return Song::firstOrCreate(
            [
                'title' => $title,
                'artist' => $artist,
            ],
            [
                'id' => Str::ulid(),
                'spotify_track_id' => $track['id'],
                'spotify_data' => $track,
            ]
        );
    }

    /**
     * 自動紐付けマッピングを作成
     */
    protected function createAutoLinkMapping(string $normalizedText, string $songId): void
    {
        DB::transaction(function () use ($normalizedText, $songId) {
            $mapping = TimestampSongMapping::where('normalized_text', $normalizedText)->first();

            if ($mapping) {
                $mapping->update([
                    'song_id' => $songId,
                    'is_not_song' => false,
                    'status' => TimestampSongMapping::STATUS_LINKED,
                    'is_manual' => false,
                    'confidence' => 0.8, // 自動紐付けは0.8
                ]);
            } else {
                TimestampSongMapping::create([
                    'id' => Str::ulid(),
                    'normalized_text' => $normalizedText,
                    'song_id' => $songId,
                    'is_not_song' => false,
                    'status' => TimestampSongMapping::STATUS_LINKED,
                    'is_manual' => false,
                    'confidence' => 0.8,
                ]);
            }
        });
    }

    /**
     * 保留マッピングを作成
     */
    protected function createPendingMapping(string $normalizedText, string $songId, float $similarity): void
    {
        DB::transaction(function () use ($normalizedText, $songId, $similarity) {
            $mapping = TimestampSongMapping::where('normalized_text', $normalizedText)->first();

            if ($mapping) {
                $mapping->update([
                    'song_id' => $songId,
                    'is_not_song' => false,
                    'status' => TimestampSongMapping::STATUS_PENDING,
                    'is_manual' => false,
                    'confidence' => $similarity,
                ]);
            } else {
                TimestampSongMapping::create([
                    'id' => Str::ulid(),
                    'normalized_text' => $normalizedText,
                    'song_id' => $songId,
                    'is_not_song' => false,
                    'status' => TimestampSongMapping::STATUS_PENDING,
                    'is_manual' => false,
                    'confidence' => $similarity,
                ]);
            }
        });
    }

    /**
     * ログ出力
     */
    protected function log(string $level, string $message): void
    {
        Log::$level('[AutoLinkService] '.$message);
    }
}
