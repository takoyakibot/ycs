<?php

namespace App\Services;

use App\Helpers\TextNormalizer;
use App\Models\Song;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use Illuminate\Support\Facades\Log;

class AutoLinkService
{
    /**
     * 未紐付けのタイムスタンプを既存楽曲マスタと照合し、自動紐付けする
     *
     * @param  int  $limit  処理件数上限
     * @param  callable|null  $onProgress  進捗コールバック function(string $message): void
     * @param  string|null  $channelId  チャンネルIDフィルタ
     * @return array{processed: int, linked: int, failed: int, skipped: int}
     */
    public function autoLinkUnlinkedTimestamps(int $limit = 100, ?callable $onProgress = null, ?string $channelId = null): array
    {
        $result = [
            'processed' => 0,
            'linked' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        $unlinkedTexts = $this->getUnlinkedTexts($limit, $channelId);

        if (empty($unlinkedTexts)) {
            $onProgress && $onProgress('未紐付けのタイムスタンプが見つかりませんでした。');

            return $result;
        }

        $onProgress && $onProgress(sprintf('%d件の未紐付けテキストを処理します。', count($unlinkedTexts)));

        foreach ($unlinkedTexts as $index => $item) {
            $result['processed']++;

            try {
                $linkResult = $this->processAutoLink($item['normalized_text']);

                if ($linkResult === 'linked') {
                    $result['linked']++;
                    $onProgress && $onProgress(sprintf('[%d/%d] 紐付け成功: %s', $index + 1, count($unlinkedTexts), $item['text']));
                } else {
                    $result['skipped']++;
                    $onProgress && $onProgress(sprintf('[%d/%d] 一致なし: %s', $index + 1, count($unlinkedTexts), $item['text']));
                }
            } catch (\Exception $e) {
                $result['failed']++;
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
     * @param  string|null  $channelId  チャンネルIDフィルタ
     * @return array<array{text: string, normalized_text: string}>
     */
    protected function getUnlinkedTexts(int $limit, ?string $channelId = null): array
    {
        return TsItem::selectRaw('MIN(ts_items.text) as text, ts_items.normalized_text')
            ->leftJoin('timestamp_song_mappings', 'ts_items.normalized_text', '=', 'timestamp_song_mappings.normalized_text')
            ->whereNotNull('ts_items.text')
            ->where('ts_items.text', '!=', '')
            ->whereNotNull('ts_items.normalized_text')
            ->where('ts_items.is_display', 1)
            ->where('ts_items.type', '!=', '3')
            ->whereHas('archive', function ($q) use ($channelId) {
                $q->where('is_display', 1);
                if ($channelId !== null) {
                    $q->where('channel_id', $channelId);
                }
            })
            ->whereNull('timestamp_song_mappings.id')
            ->groupBy('ts_items.normalized_text')
            ->orderByRaw('MIN(ts_items.text) asc')
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
     * normalized_textからtitle/artistを抽出し、songs.normalized_titleと完全一致照合。
     * 一致すれば自動紐付け、不一致なら未紐付けのまま。
     *
     * @return string 'linked'|'not_found'
     */
    protected function processAutoLink(string $normalizedText): string
    {
        $existingSong = $this->findSongByNormalizedText($normalizedText);
        if ($existingSong) {
            $this->createAutoLinkMapping($normalizedText, $existingSong->id);

            return 'linked';
        }

        return 'not_found';
    }

    /**
     * normalized_textから楽曲名を抽出し、既存songsテーブルと照合する
     *
     * extractSongInfo()で分割し、title部分とartist部分の両方で
     * songs.normalized_titleを検索する（順序が不定のため）
     */
    protected function findSongByNormalizedText(string $normalizedText): ?Song
    {
        $songInfo = TextNormalizer::extractSongInfo($normalizedText);

        $candidates = [];

        // parts[1]（title部分）でnormalized_titleを検索
        if (! empty($songInfo['title'])) {
            $song = Song::where('normalized_title', $songInfo['title'])->first();
            if ($song) {
                $candidates[] = $song;
            }
        }

        // parts[0]（artist部分）でもnormalized_titleを検索（順序が逆の場合に対応）
        if (! empty($songInfo['artist'])) {
            $song = Song::where('normalized_title', $songInfo['artist'])->first();
            if ($song && ! in_array($song->id, array_map(fn ($s) => $s->id, $candidates))) {
                $candidates[] = $song;
            }
        }

        // 区切りなしの場合
        if (empty($songInfo['artist'])) {
            return $candidates[0] ?? null;
        }

        // 候補が1つならそのまま返す
        if (count($candidates) === 1) {
            return $candidates[0];
        }

        // 候補が複数ある場合、artist側も一致するものを優先
        foreach ($candidates as $candidate) {
            $normalizedArtist = $candidate->normalized_artist;
            if ($normalizedArtist === $songInfo['artist'] || $normalizedArtist === $songInfo['title']) {
                return $candidate;
            }
        }

        return $candidates[0] ?? null;
    }

    /**
     * 自動紐付けマッピングを作成
     */
    protected function createAutoLinkMapping(string $normalizedText, string $songId): void
    {
        TimestampSongMapping::updateOrCreate(
            ['normalized_text' => $normalizedText],
            [
                'song_id' => $songId,
                'is_not_song' => false,
                'status' => TimestampSongMapping::STATUS_LINKED,
                'is_manual' => false,
                'confidence' => 0.8,
            ]
        );
    }

    /**
     * ログ出力
     */
    protected function log(string $level, string $message): void
    {
        Log::log($level, '[AutoLinkService] '.$message);
    }
}
