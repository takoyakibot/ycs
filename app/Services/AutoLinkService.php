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
        $result = $this->findSongByNormalizedText($normalizedText);
        if ($result) {
            $this->createAutoLinkMapping($normalizedText, $result['song']->id, $result['artist_matched']);

            return 'linked';
        }

        return 'not_found';
    }

    /**
     * normalized_textから楽曲名を抽出し、既存songsテーブルと照合する
     *
     * extractSongInfo()で分割し、title部分とartist部分の両方で
     * songs.normalized_titleを検索する（順序が不定のため）。
     * アーティスト一致判定はsong_tagsとのパーツ完全一致で行う。
     *
     * @return array{song: Song, artist_matched: bool}|null
     */
    protected function findSongByNormalizedText(string $normalizedText): ?array
    {
        $songInfo = TextNormalizer::extractSongInfo($normalizedText);

        $candidates = [];

        // parts[1]（title部分）でnormalized_titleを検索
        if (! empty($songInfo['title'])) {
            $songs = Song::where('normalized_title', $songInfo['title'])->with('tags')->get();
            foreach ($songs as $song) {
                $candidates[] = $song;
            }
        }

        // parts[0]（artist部分）でもnormalized_titleを検索（順序が逆の場合に対応）
        if (! empty($songInfo['artist'])) {
            $songs = Song::where('normalized_title', $songInfo['artist'])->with('tags')->get();
            foreach ($songs as $song) {
                if (! in_array($song->id, array_map(fn ($s) => $s->id, $candidates))) {
                    $candidates[] = $song;
                }
            }
        }

        if (empty($candidates)) {
            return null;
        }

        // アーティスト情報がない場合（区切りなし）
        if (empty($songInfo['artist'])) {
            return ['song' => $candidates[0], 'artist_matched' => false];
        }

        // タグマッチング: テキストの非タイトル部分と候補のタグをパーツ完全一致で照合
        foreach ($candidates as $candidate) {
            $tagValues = $candidate->tags->pluck('value')
                ->map(fn ($v) => TextNormalizer::normalize($v))
                ->toArray();

            // 候補のタイトルがどちらのパーツに一致したかで、照合対象を決める
            $matchPart = ($candidate->normalized_title === $songInfo['title'])
                ? $songInfo['artist']
                : $songInfo['title'];

            $normalizedMatchPart = TextNormalizer::normalize($matchPart);

            if (in_array($normalizedMatchPart, $tagValues, true)) {
                return ['song' => $candidate, 'artist_matched' => true];
            }
        }

        // アーティスト不一致だが候補はある
        return ['song' => $candidates[0], 'artist_matched' => false];
    }

    /**
     * 自動紐付けマッピングを作成
     *
     * アーティスト名まで一致する場合は確定扱い（is_manual=true、即公開）、
     * タイトルのみの一致（アーティスト情報なし・不一致）はレビュー待ち（is_manual=false）とする。
     */
    protected function createAutoLinkMapping(string $normalizedText, string $songId, bool $artistMatched): void
    {
        TimestampSongMapping::updateOrCreate(
            ['normalized_text' => $normalizedText],
            [
                'song_id' => $songId,
                'is_not_song' => false,
                'status' => TimestampSongMapping::STATUS_LINKED,
                'is_manual' => $artistMatched,
                'confidence' => $artistMatched ? 0.9 : 0.8,
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
