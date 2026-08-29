<?php

namespace App\Services;

use App\Helpers\TextNormalizer;
use App\Models\Song;
use App\Models\TimestampDecomposition;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TimestampDecompositionService
{
    /**
     * 自動選択の確信度閾値
     */
    public const AUTO_SELECT_THRESHOLD = 0.8;

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
            // 「楽曲でない」とマークされたタイムスタンプを除外
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('timestamp_song_mappings')
                    ->whereColumn('timestamp_song_mappings.normalized_text', 'ts_items.normalized_text')
                    ->where('timestamp_song_mappings.is_not_song', true);
            })
            // 手動紐付け済みのタイムスタンプを除外（自動紐付けはTS分解で再処理可能）
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('timestamp_song_mappings')
                    ->whereColumn('timestamp_song_mappings.normalized_text', 'ts_items.normalized_text')
                    ->whereNotNull('timestamp_song_mappings.song_id')
                    ->where('timestamp_song_mappings.is_manual', true);
            })
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('timestamp_decompositions')
                    ->whereColumn('timestamp_decompositions.normalized_text', 'ts_items.normalized_text');
            })
            ->groupBy('text', 'normalized_text')
            ->orderByRaw('MIN(ts_items.id)'); // GROUP BYとの互換性のためMIN()を使用

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
                    try {
                        $this->createDecomposition($item->text, $item->normalized_text, $decomposition);
                        $count++;
                    } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                        // 正規化テキストが重複している場合はスキップ（異なる元テキストが同じ正規化結果になる場合）
                        continue;
                    }
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
            'id' => (string) Str::ulid(),
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
            // 「楽曲でない」とマークされたタイムスタンプを除外
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('timestamp_song_mappings')
                    ->whereColumn('timestamp_song_mappings.normalized_text', 'timestamp_decompositions.normalized_text')
                    ->where('timestamp_song_mappings.is_not_song', true);
            })
            // 手動紐付け済みのタイムスタンプを除外（自動紐付けはTS分解で再処理可能）
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('timestamp_song_mappings')
                    ->whereColumn('timestamp_song_mappings.normalized_text', 'timestamp_decompositions.normalized_text')
                    ->whereNotNull('timestamp_song_mappings.song_id')
                    ->where('timestamp_song_mappings.is_manual', true);
            })
            ->orderBy('separator_count', 'asc') // パーツが少ないものから処理（簡単なものから）
            ->orderBy('created_at', 'asc')
            ->first();
    }

    /**
     * 選別結果を保存
     *
     * @param  array  $titleIndices  楽曲名パーツのインデックス配列
     * @param  array  $artistIndices  アーティスト名パーツのインデックス配列
     * @param  bool  $enableCascade  カスケード処理を有効にするか
     * @param  array{title?: ?string, artist?: ?string}  $overrides
     *                                                               パーツ連結の代わりに使う確定値。補足除去候補の確定や画面上での
     *                                                               微調整で使う。キーが存在する場合のみ優先される。
     * @return array{decomposition: TimestampDecomposition, cascaded_count: int}
     */
    public function saveSelection(
        string $id,
        array $titleIndices,
        array $artistIndices,
        bool $enableCascade = true,
        array $overrides = []
    ): array {
        $decomposition = TimestampDecomposition::findOrFail($id);

        // 複数パーツを元の区切り文字を維持して連結（overrides があればそちらを優先）
        $derivedTitle = array_key_exists('title', $overrides)
            ? trim((string) $overrides['title'])
            : $this->joinPartsWithOriginalSeparators(
                $decomposition->original_text,
                $decomposition->parts,
                $titleIndices
            );
        $derivedArtist = array_key_exists('artist', $overrides)
            ? trim((string) $overrides['artist'])
            : $this->joinPartsWithOriginalSeparators(
                $decomposition->original_text,
                $decomposition->parts,
                $artistIndices
            );

        // DBには最初のインデックスのみ保存（後方互換性のため）
        $decomposition->update([
            'title_part_index' => ! empty($titleIndices) ? $titleIndices[0] : null,
            'artist_part_index' => ! empty($artistIndices) ? $artistIndices[0] : null,
            'derived_title' => $derivedTitle ?: null,
            'derived_artist' => $derivedArtist ?: null,
            'status' => TimestampDecomposition::STATUS_SELECTED,
            'updated_by' => Auth::id(),
        ]);

        $cascadedCount = 0;

        // アーティストが設定された場合、同じアーティストを持つ他のタイムスタンプにカスケード処理
        if ($enableCascade && $derivedArtist) {
            $cascadedCount = $this->cascadeArtistSelection($derivedArtist, $decomposition->id);
        }

        return [
            'decomposition' => $decomposition->fresh(),
            'cascaded_count' => $cascadedCount,
        ];
    }

    /**
     * 選択されたパーツを元の区切り文字を維持して連結
     */
    private function joinPartsWithOriginalSeparators(string $originalText, array $parts, array $indices): string
    {
        if (empty($indices)) {
            return '';
        }

        if (count($indices) === 1) {
            return $this->extractRangeFromOriginal($originalText, $parts, $indices[0], $indices[0]);
        }

        sort($indices);

        // 連続するインデックスのグループを作成
        $groups = [];
        $currentGroup = [$indices[0]];

        for ($i = 1; $i < count($indices); $i++) {
            if ($indices[$i] === $indices[$i - 1] + 1) {
                $currentGroup[] = $indices[$i];
            } else {
                $groups[] = $currentGroup;
                $currentGroup = [$indices[$i]];
            }
        }
        $groups[] = $currentGroup;

        // 各グループを元テキストから抽出して連結
        $result = [];
        foreach ($groups as $group) {
            $start = $group[0];
            $end = $group[count($group) - 1];
            $result[] = $this->extractRangeFromOriginal($originalText, $parts, $start, $end);
        }

        return implode(' / ', $result);
    }

    /**
     * 元テキストから指定範囲のパーツを抽出（区切り文字を維持）
     */
    private function extractRangeFromOriginal(string $originalText, array $parts, int $startIndex, int $endIndex): string
    {
        $currentPos = 0;
        $startPos = -1;
        $endPos = -1;

        for ($i = 0; $i < count($parts); $i++) {
            $partPos = mb_strpos($originalText, $parts[$i], $currentPos);
            if ($partPos === false) {
                continue;
            }

            if ($i === $startIndex) {
                $startPos = $partPos;
            }
            if ($i === $endIndex) {
                $endPos = $partPos + mb_strlen($parts[$i]);
                break;
            }
            $currentPos = $partPos + mb_strlen($parts[$i]);
        }

        if ($startPos === -1 || $endPos === -1) {
            if ($startIndex === $endIndex) {
                return $parts[$startIndex] ?? '';
            }

            return implode(' / ', array_slice($parts, $startIndex, $endIndex - $startIndex + 1));
        }

        $separatorOrWhitespace = '/^[\s\/／\-−－:：|｜]*$/u';

        if ($startIndex === 0) {
            $leading = mb_substr($originalText, 0, $startPos);
            if (preg_match($separatorOrWhitespace, $leading)) {
                $startPos = 0;
            }
        }

        if ($endIndex === count($parts) - 1) {
            $trailing = mb_substr($originalText, $endPos);
            if (preg_match($separatorOrWhitespace, $trailing)) {
                $endPos = mb_strlen($originalText);
            }
        }

        return trim(mb_substr($originalText, $startPos, $endPos - $startPos));
    }

    /**
     * 全体を楽曲名として保存（分割しない）
     *
     * @return array{decomposition: TimestampDecomposition}
     */
    public function saveAsWholeTitle(string $id): array
    {
        $decomposition = TimestampDecomposition::findOrFail($id);

        // 元テキスト全体を楽曲名として設定
        $decomposition->update([
            'title_part_index' => null, // パーツ選択ではないのでnull
            'artist_part_index' => null,
            'derived_title' => $decomposition->original_text,
            'derived_artist' => null,
            'status' => TimestampDecomposition::STATUS_SELECTED,
            'updated_by' => Auth::id(),
        ]);

        return [
            'decomposition' => $decomposition->fresh(),
        ];
    }

    /**
     * アーティスト選別のカスケード処理
     * 同じアーティスト名を持つpendingなタイムスタンプを自動的に処理
     *
     * @param  string  $artistName  確定したアーティスト名
     * @param  string  $excludeId  カスケード元のID（除外）
     * @return int 処理された件数
     */
    public function cascadeArtistSelection(string $artistName, string $excludeId): int
    {
        $normalizedArtist = TextNormalizer::normalize($artistName);
        $count = 0;

        // pendingなタイムスタンプを検索
        TimestampDecomposition::pending()
            ->where('id', '!=', $excludeId)
            ->chunk(100, function ($decompositions) use ($normalizedArtist, $artistName, &$count) {
                foreach ($decompositions as $decomposition) {
                    $matchResult = $this->findArtistInParts($decomposition->parts, $normalizedArtist);

                    if ($matchResult === null) {
                        continue;
                    }

                    $artistIndex = $matchResult['artist_index'];
                    $titleIndex = $matchResult['title_index'];
                    $derivedTitle = $titleIndex !== null ? $decomposition->parts[$titleIndex] : null;

                    // カスケード処理で更新
                    $decomposition->update([
                        'artist_part_index' => $artistIndex,
                        'title_part_index' => $titleIndex,
                        'derived_artist' => $artistName,
                        'derived_title' => $derivedTitle,
                        'status' => TimestampDecomposition::STATUS_AUTO_MATCHED,
                        'confidence' => 0.9,
                        'updated_by' => Auth::id(),
                    ]);

                    // 楽曲マスタに紐付け
                    if ($derivedTitle) {
                        try {
                            $this->linkToSong($decomposition->fresh());
                        } catch (\Exception $e) {
                            \Illuminate\Support\Facades\Log::warning('Cascade link failed: '.$decomposition->id, [
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    $count++;
                }
            });

        return $count;
    }

    /**
     * パーツ配列からアーティスト名を検索
     *
     * @param  array  $parts  パーツ配列
     * @param  string  $normalizedArtist  正規化されたアーティスト名
     * @return array{artist_index: int, title_index: int|null}|null マッチした場合はインデックス情報、なければnull
     */
    private function findArtistInParts(array $parts, string $normalizedArtist): ?array
    {
        foreach ($parts as $index => $part) {
            $normalizedPart = TextNormalizer::normalize($part);

            // 無視すべきパーツはスキップ。
            // 判定は分解画面と同じ isIgnorablePart() に寄せる。
            // キーワードとの完全一致で判定していたため、画面ではノイズとして
            // 捨てるパーツ（"cover2" 等）を曲名として採用し、そのまま
            // 楽曲マスタを作ってしまっていた
            if (TextNormalizer::isIgnorablePart($part)) {
                continue;
            }

            if ($normalizedPart === $normalizedArtist) {
                // アーティストが見つかった場合、楽曲名を推定
                $titleIndex = $this->guessTitleIndex($parts, $index);

                // 曲名候補が複数あり曖昧な場合はマッチなしとする
                if ($titleIndex === false) {
                    return null;
                }

                return [
                    'artist_index' => $index,
                    'title_index' => $titleIndex,
                ];
            }
        }

        return null;
    }

    /**
     * アーティストインデックス以外のパーツから楽曲名インデックスを推定
     *
     * @param  array  $parts  パーツ配列
     * @param  int  $artistIndex  アーティストのインデックス
     * @return int|null|false 候補1つ→インデックス、候補0→null、候補複数→false
     */
    private function guessTitleIndex(array $parts, int $artistIndex): int|null|false
    {
        $candidateIndices = [];

        foreach ($parts as $index => $part) {
            if ($index === $artistIndex) {
                continue;
            }

            // 無視すべきパーツはスキップ（判定は findArtistInParts と揃える）
            if (TextNormalizer::isIgnorablePart($part)) {
                continue;
            }

            $candidateIndices[] = $index;
        }

        // 候補が1つだけならそれを楽曲名とする
        if (count($candidateIndices) === 1) {
            return $candidateIndices[0];
        }

        // 候補が複数ある場合は曖昧（false）
        // 例: "RE: I AM / Aimer" → parts=["RE","I AM","Aimer"] で
        // 候補が ["RE","I AM"] の2つになるが、先頭を選ぶと曲名が欠ける
        if (count($candidateIndices) > 1) {
            return false;
        }

        // 候補なし（全部ノイズ）
        return null;
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
     * 操作を取り消し（undo）
     *
     * @return array{undone_count: int}
     */
    public function undoAction(string $id): array
    {
        $decomposition = TimestampDecomposition::findOrFail($id);
        $undoneCount = 1;

        // 紐付けられた楽曲マッピングを解除
        if ($decomposition->song_id) {
            TimestampSongMapping::where('normalized_text', $decomposition->normalized_text)
                ->update([
                    'song_id' => null,
                    'is_manual' => false,
                    'status' => 'pending',
                    'updated_by' => Auth::id(),
                ]);
        }

        // カスケード処理されたアイテムも元に戻す（同じupdated_byかつ近い時間に更新されたもの）
        $cascadedItems = TimestampDecomposition::where('id', '!=', $id)
            ->where('status', TimestampDecomposition::STATUS_AUTO_MATCHED)
            ->where('updated_by', $decomposition->updated_by)
            ->whereBetween('updated_at', [
                $decomposition->updated_at->subSeconds(5),
                $decomposition->updated_at->addSeconds(5),
            ])
            ->get();

        foreach ($cascadedItems as $item) {
            // マッピングを解除
            if ($item->song_id) {
                TimestampSongMapping::where('normalized_text', $item->normalized_text)
                    ->update([
                        'song_id' => null,
                        'is_manual' => false,
                        'status' => 'pending',
                        'updated_by' => Auth::id(),
                    ]);
            }

            // ステータスをpendingに戻す
            $item->update([
                'title_part_index' => null,
                'artist_part_index' => null,
                'derived_title' => null,
                'derived_artist' => null,
                'status' => TimestampDecomposition::STATUS_PENDING,
                'song_id' => null,
                'confidence' => null,
                'updated_by' => Auth::id(),
            ]);
            $undoneCount++;
        }

        // 元のアイテムをpendingに戻す
        $decomposition->update([
            'title_part_index' => null,
            'artist_part_index' => null,
            'derived_title' => null,
            'derived_artist' => null,
            'status' => TimestampDecomposition::STATUS_PENDING,
            'song_id' => null,
            'updated_by' => Auth::id(),
        ]);

        return [
            'undone_count' => $undoneCount,
        ];
    }

    /**
     * 選別結果から楽曲マスタを検索・作成し、マッピングを作成
     */
    public function linkToSong(TimestampDecomposition $decomposition): ?Song
    {
        if (! $decomposition->derived_title) {
            return null;
        }

        $title = $decomposition->derived_title;
        $artist = $decomposition->derived_artist ?? '';

        // 正規化済みテキストで既存楽曲を検索（文字バリエーションによる重複を防止）
        $normalizedTitle = TextNormalizer::normalize($title);
        $normalizedArtist = TextNormalizer::normalize($artist);

        if ($normalizedArtist === '') {
            return null;
        }

        $song = Song::where('normalized_title', $normalizedTitle)
            ->where('normalized_artist', $normalizedArtist)
            ->first();

        // 正規化検索で見つからない場合、生テキストでも検索（ユニーク制約と同じ条件）
        if (! $song) {
            $song = Song::where('title', $title)
                ->where('artist', $artist)
                ->first();
        }

        // 見つからなければ新規作成
        if (! $song) {
            $song = Song::create([
                'id' => (string) Str::ulid(),
                'title' => $title,
                'artist' => $artist,
                'created_by' => Auth::id(),
            ]);
        }

        // decompositionにsong_idを紐付け
        $decomposition->update([
            'song_id' => $song->id,
            'updated_by' => Auth::id(),
        ]);

        // timestamp_song_mappingsにマッピングを作成
        $mapping = TimestampSongMapping::firstOrNew(
            ['normalized_text' => $decomposition->normalized_text]
        );

        if (! $mapping->exists) {
            $mapping->id = (string) Str::ulid();
            $mapping->created_by = Auth::id();
        }

        $mapping->fill([
            'song_id' => $song->id,
            'is_not_song' => false,
            'is_manual' => true,
            'status' => 'linked',
            'confidence' => 1.0,
            'updated_by' => Auth::id(),
        ]);
        $mapping->save();

        return $song;
    }

    /**
     * 統計情報を取得
     */
    public function getStatistics(): array
    {
        // 「楽曲でない」および「手動紐付け済み」を除外したpending件数
        $pendingCount = TimestampDecomposition::where('status', TimestampDecomposition::STATUS_PENDING)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('timestamp_song_mappings')
                    ->whereColumn('timestamp_song_mappings.normalized_text', 'timestamp_decompositions.normalized_text')
                    ->where('timestamp_song_mappings.is_not_song', true);
            })
            // 手動紐付け済みのタイムスタンプを除外（自動紐付けはTS分解で再処理可能）
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('timestamp_song_mappings')
                    ->whereColumn('timestamp_song_mappings.normalized_text', 'timestamp_decompositions.normalized_text')
                    ->whereNotNull('timestamp_song_mappings.song_id')
                    ->where('timestamp_song_mappings.is_manual', true);
            })
            ->count();

        return [
            'total' => TimestampDecomposition::count(),
            'pending' => $pendingCount,
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
            // 「楽曲でない」とマークされたタイムスタンプを除外
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('timestamp_song_mappings')
                    ->whereColumn('timestamp_song_mappings.normalized_text', 'ts_items.normalized_text')
                    ->where('timestamp_song_mappings.is_not_song', true);
            })
            // 手動紐付け済みのタイムスタンプを除外（自動紐付けはTS分解で再処理可能）
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('timestamp_song_mappings')
                    ->whereColumn('timestamp_song_mappings.normalized_text', 'ts_items.normalized_text')
                    ->whereNotNull('timestamp_song_mappings.song_id')
                    ->where('timestamp_song_mappings.is_manual', true);
            })
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('timestamp_decompositions')
                    ->whereColumn('timestamp_decompositions.normalized_text', 'ts_items.normalized_text');
            })
            ->whereRaw("text REGEXP '[/／−－:：|｜-]'") // ハイフンは末尾に、長音記号（ー）は含まない（誤検出防止）
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
                    try {
                        if ($this->linkToSong($decomposition)) {
                            $count++;
                        }
                    } catch (\Exception $e) {
                        \Illuminate\Support\Facades\Log::error('Failed to link decomposition: '.$decomposition->id, [
                            'error' => $e->getMessage(),
                            'decomposition_id' => $decomposition->id,
                            'derived_title' => $decomposition->derived_title,
                        ]);

                        // エラーをスキップして続行
                        continue;
                    }
                }
            });

        return $count;
    }

    /**
     * 自動判定されたアイテムの一覧を取得
     *
     * 紐付け済み（song_id あり）と未紐付けの両方を返す。
     * TS分解画面の統計「自動: N件」と件数を一致させるため。
     *
     * @param  string|null  $filter  'linked' | 'unlinked' | 'empty_artist' | それ以外は絞り込みなし
     */
    public function getAutoMatchedList(?string $filter = null, int $perPage = 50): LengthAwarePaginator
    {
        $query = TimestampDecomposition::with('song')
            ->where('status', TimestampDecomposition::STATUS_AUTO_MATCHED)
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($filter === 'linked') {
            $query->whereNotNull('song_id');
        } elseif ($filter === 'unlinked') {
            $query->whereNull('song_id');
        } elseif ($filter === 'empty_artist') {
            // 紐付け済みなら楽曲マスタのアーティスト名、未紐付けなら判定結果を見る
            $query->where(function ($outer) {
                $outer->where(function ($q) {
                    $q->whereNull('song_id')
                        ->where(function ($inner) {
                            $inner->whereNull('derived_artist')->orWhere('derived_artist', '');
                        });
                })->orWhere(function ($q) {
                    $q->whereNotNull('song_id')
                        ->whereHas('song', function ($songQuery) {
                            $songQuery->whereNull('artist')->orWhere('artist', '');
                        });
                });
            });
        }

        return $query->paginate($perPage);
    }
}
