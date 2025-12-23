<?php

namespace App\Services;

use App\Helpers\TextNormalizer;
use App\Models\Song;
use App\Models\TimestampDecomposition;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TimestampDecompositionService
{
    /**
     * 自動選択の確信度閾値
     */
    private const AUTO_SELECT_THRESHOLD = 0.8;

    /**
     * 区切り文字を含むタイムスタンプをスキャンして分解結果をDBに保存
     *
     * @return int 新規追加された件数
     */
    public function scanAndDecompose(): int
    {
        $count = 0;

        // 区切り文字を含み、まだ分解されていないタイムスタンプを取得
        $query = TsItem::select('text', 'normalized_text')
            ->whereNotNull('text')
            ->where('text', '!=', '')
            ->where('is_display', true)
            ->whereHas('archive', fn ($q) => $q->where('is_display', true))
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('timestamp_decompositions')
                    ->whereColumn('timestamp_decompositions.normalized_text', 'ts_items.normalized_text');
            })
            ->groupBy('text', 'normalized_text');

        // チャンク処理で大量データに対応
        $query->chunk(500, function ($items) use (&$count) {
            foreach ($items as $item) {
                // 区切り文字を含むかチェック
                if (! TextNormalizer::hasSeparators($item->text)) {
                    continue;
                }

                $decomposition = $this->decompose($item->text);

                // パーツが2つ以上ある場合のみ保存
                if ($decomposition['separator_count'] > 0) {
                    $this->createDecomposition($item->text, $item->normalized_text, $decomposition);
                    $count++;
                }
            }
        });

        return $count;
    }

    /**
     * テキストをパーツに分解
     *
     * @return array{
     *     parts: string[],
     *     separator_count: int,
     *     detection: array{title_index: int|null, artist_index: int|null, confidence: float, ignore_indices: int[]}
     * }
     */
    public function decompose(string $text): array
    {
        $splitResult = TextNormalizer::splitBySeparators($text);
        $detection = TextNormalizer::detectTitleArtistPattern($splitResult['parts']);

        return [
            'parts' => $splitResult['parts'],
            'separator_count' => $splitResult['separator_count'],
            'detection' => $detection,
        ];
    }

    /**
     * 分解結果をDBに保存
     */
    private function createDecomposition(string $originalText, string $normalizedText, array $decomposition): TimestampDecomposition
    {
        $detection = $decomposition['detection'];

        // 確信度が閾値以上の場合は自動選択状態にする
        $status = TimestampDecomposition::STATUS_PENDING;
        $titleIndex = null;
        $artistIndex = null;
        $derivedTitle = null;
        $derivedArtist = null;

        if ($detection['confidence'] >= self::AUTO_SELECT_THRESHOLD) {
            $status = TimestampDecomposition::STATUS_AUTO_MATCHED;
            $titleIndex = $detection['title_index'];
            $artistIndex = $detection['artist_index'];

            if ($titleIndex !== null) {
                $derivedTitle = $decomposition['parts'][$titleIndex] ?? null;
            }
            if ($artistIndex !== null) {
                $derivedArtist = $decomposition['parts'][$artistIndex] ?? null;
            }
        }

        return TimestampDecomposition::create([
            'id' => Str::ulid()->toString(),
            'normalized_text' => $normalizedText,
            'original_text' => $originalText,
            'parts' => $decomposition['parts'],
            'separator_count' => $decomposition['separator_count'],
            'title_part_index' => $titleIndex,
            'artist_part_index' => $artistIndex,
            'derived_title' => $derivedTitle,
            'derived_artist' => $derivedArtist,
            'status' => $status,
            'confidence' => $detection['confidence'],
        ]);
    }

    /**
     * 次の未処理アイテムを取得
     */
    public function getNextPending(): ?TimestampDecomposition
    {
        return TimestampDecomposition::pending()
            ->orderBy('separator_count', 'asc') // パーツが少ないものから処理（簡単なものから）
            ->orderBy('created_at', 'asc')
            ->first();
    }

    /**
     * 選別結果を保存
     *
     * @param  int|null  $titleIndex  楽曲名パーツのインデックス
     * @param  int|null  $artistIndex  アーティスト名パーツのインデックス
     */
    public function saveSelection(string $id, ?int $titleIndex, ?int $artistIndex): TimestampDecomposition
    {
        $decomposition = TimestampDecomposition::findOrFail($id);

        $derivedTitle = null;
        $derivedArtist = null;

        if ($titleIndex !== null && isset($decomposition->parts[$titleIndex])) {
            $derivedTitle = $decomposition->parts[$titleIndex];
        }
        if ($artistIndex !== null && isset($decomposition->parts[$artistIndex])) {
            $derivedArtist = $decomposition->parts[$artistIndex];
        }

        $decomposition->update([
            'title_part_index' => $titleIndex,
            'artist_part_index' => $artistIndex,
            'derived_title' => $derivedTitle,
            'derived_artist' => $derivedArtist,
            'status' => TimestampDecomposition::STATUS_SELECTED,
            'updated_by' => Auth::id(),
        ]);

        return $decomposition->fresh();
    }

    /**
     * スキップとしてマーク
     */
    public function markAsSkipped(string $id): void
    {
        $decomposition = TimestampDecomposition::findOrFail($id);

        $decomposition->update([
            'status' => TimestampDecomposition::STATUS_SKIPPED,
            'updated_by' => Auth::id(),
        ]);
    }

    /**
     * 選別結果から楽曲マスタを検索・作成し、マッピングを作成
     */
    public function linkToSong(TimestampDecomposition $decomposition): ?Song
    {
        if (! $decomposition->derived_title) {
            return null;
        }

        $normalizedTitle = TextNormalizer::normalize($decomposition->derived_title);
        $normalizedArtist = $decomposition->derived_artist
            ? TextNormalizer::normalize($decomposition->derived_artist)
            : null;

        // 既存の楽曲を検索
        $query = Song::where('normalized_title', $normalizedTitle);
        if ($normalizedArtist) {
            $query->where('normalized_artist', $normalizedArtist);
        }
        $song = $query->first();

        // 見つからなければ新規作成
        if (! $song) {
            $song = Song::create([
                'id' => Str::ulid()->toString(),
                'title' => $decomposition->derived_title,
                'artist' => $decomposition->derived_artist ?? '',
                'created_by' => Auth::id(),
            ]);
        }

        // decompositionにsong_idを紐付け
        $decomposition->update([
            'song_id' => $song->id,
            'updated_by' => Auth::id(),
        ]);

        // timestamp_song_mappingsにマッピングを作成
        TimestampSongMapping::updateOrCreate(
            ['normalized_text' => $decomposition->normalized_text],
            [
                'song_id' => $song->id,
                'is_not_song' => false,
                'is_manual' => true,
                'status' => 'linked',
                'confidence' => 1.0,
                'updated_by' => Auth::id(),
            ]
        );

        return $song;
    }

    /**
     * 統計情報を取得
     */
    public function getStatistics(): array
    {
        return [
            'total' => TimestampDecomposition::count(),
            'pending' => TimestampDecomposition::where('status', TimestampDecomposition::STATUS_PENDING)->count(),
            'selected' => TimestampDecomposition::where('status', TimestampDecomposition::STATUS_SELECTED)->count(),
            'skipped' => TimestampDecomposition::where('status', TimestampDecomposition::STATUS_SKIPPED)->count(),
            'auto_matched' => TimestampDecomposition::where('status', TimestampDecomposition::STATUS_AUTO_MATCHED)->count(),
            'unscanned' => $this->countUnscannedTimestamps(),
        ];
    }

    /**
     * まだスキャンされていないタイムスタンプの数を取得
     */
    private function countUnscannedTimestamps(): int
    {
        return TsItem::select('normalized_text')
            ->whereNotNull('text')
            ->where('text', '!=', '')
            ->where('is_display', true)
            ->whereHas('archive', fn ($q) => $q->where('is_display', true))
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('timestamp_decompositions')
                    ->whereColumn('timestamp_decompositions.normalized_text', 'ts_items.normalized_text');
            })
            ->whereRaw("text REGEXP '[\/／\-−－ー:：|｜]'")
            ->groupBy('normalized_text')
            ->get()
            ->count();
    }

    /**
     * 自動判定済みのアイテムを一括で楽曲マスタに紐付け
     *
     * @return int 紐付けされた件数
     */
    public function bulkLinkAutoMatched(): int
    {
        $count = 0;

        TimestampDecomposition::where('status', TimestampDecomposition::STATUS_AUTO_MATCHED)
            ->whereNull('song_id')
            ->whereNotNull('derived_title')
            ->chunk(100, function ($decompositions) use (&$count) {
                foreach ($decompositions as $decomposition) {
                    if ($this->linkToSong($decomposition)) {
                        $count++;
                    }
                }
            });

        return $count;
    }
}
