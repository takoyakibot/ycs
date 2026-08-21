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
     *
     * 現在の自動判定（createDecomposition）はマスタとの完全一致でのみ確定するため、
     * この閾値はもう参照していない。database/migrations/2026_08_17_000001_redetect_auto_matched_decompositions.php
     * が過去に実行された前提で定数と detectTitleArtistPattern() を直接参照しているため、
     * 新規インストール時の再実行に備えて残す。
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
     *
     * 自動確定（auto_matched）は、無視パーツを除いた候補が2個のときに
     * マスタの楽曲と完全一致する場合のみ行う。類推や部分一致は行わない。
     */
    private function createDecomposition(string $originalText, string $normalizedText, array $decomposition): TimestampDecomposition
    {
        $parts = $decomposition['parts'];
        $match = $this->findExactMasterMatch($parts);

        $status = TimestampDecomposition::STATUS_PENDING;
        $titleIndex = $match['title_index'] ?? null;
        $artistIndex = $match['artist_index'] ?? null;
        $derivedTitle = null;
        $derivedArtist = null;
        $confidence = null;

        if ($match !== null) {
            $status = TimestampDecomposition::STATUS_AUTO_MATCHED;
            $derivedTitle = $parts[$titleIndex];
            $derivedArtist = $parts[$artistIndex];
            $confidence = 1.0;
        }

        $record = TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => $normalizedText,
            'original_text' => $originalText,
            'parts' => $parts,
            'separator_count' => $decomposition['separator_count'],
            'title_part_index' => $titleIndex,
            'artist_part_index' => $artistIndex,
            'derived_title' => $derivedTitle,
            'derived_artist' => $derivedArtist,
            'status' => $status,
            'confidence' => $confidence,
        ]);

        if ($match !== null) {
            $this->attachSongToMapping($record, $match['song']);
        }

        return $record;
    }

    /**
     * 分解パーツがマスタの楽曲と完全一致するか判定
     *
     * 無視パーツ（isIgnorablePart）を除いた候補がちょうど2個のときのみ判定する。
     * どちらが曲名でどちらがアーティストかは分からないため両方の順序を試し、
     * 片方の順序だけがマスタに完全一致する場合のみ自動確定する
     * （両方一致・どちらも一致しない場合は判断できないため確定しない）。
     *
     * @param  string[]  $parts
     * @return array{song: Song, title_index: int, artist_index: int}|null
     */
    private function findExactMasterMatch(array $parts): ?array
    {
        $candidateIndices = array_values(array_filter(
            array_keys($parts),
            fn ($index) => ! TextNormalizer::isIgnorablePart($parts[$index])
        ));

        if (count($candidateIndices) !== 2) {
            return null;
        }

        [$firstIndex, $secondIndex] = $candidateIndices;

        $orderings = [
            ['title_index' => $firstIndex, 'artist_index' => $secondIndex],
            ['title_index' => $secondIndex, 'artist_index' => $firstIndex],
        ];

        $matches = [];
        foreach ($orderings as $ordering) {
            $song = $this->findExactSong($parts[$ordering['title_index']], $parts[$ordering['artist_index']]);
            if ($song !== null) {
                $matches[] = array_merge($ordering, ['song' => $song]);
            }
        }

        if (count($matches) !== 1) {
            return null;
        }

        return $matches[0];
    }

    /**
     * 曲名・アーティスト名がマスタと完全一致する楽曲を検索
     *
     * songs.normalized_title / normalized_artist はDB照合順序（utf8mb4_unicode_ci）が
     * 絵文字・半角全角カナ・アクセント記号などを同値と判定するため、DBの検索結果を
     * PHP側でバイト完全一致に再検証してから返す（類推・あいまい検索を排除するため）。
     */
    private function findExactSong(string $titlePart, string $artistPart): ?Song
    {
        $normalizedTitle = TextNormalizer::normalize($titlePart);
        $normalizedArtist = TextNormalizer::normalize($artistPart);

        if ($normalizedTitle === '' || $normalizedArtist === '') {
            return null;
        }

        return Song::where('normalized_title', $normalizedTitle)
            ->where('normalized_artist', $normalizedArtist)
            ->get()
            ->first(fn (Song $song) => $song->normalized_title === $normalizedTitle
                && $song->normalized_artist === $normalizedArtist);
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
     * @param  array{title?: ?string, artist?: ?string}  $overrides
     *                                                               パーツ連結の代わりに使う確定値。補足除去候補の確定や画面上での
     *                                                               微調整で使う。キーが存在する場合のみ優先される。
     * @return array{decomposition: TimestampDecomposition}
     */
    public function saveSelection(
        string $id,
        array $titleIndices,
        array $artistIndices,
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

        return [
            'decomposition' => $decomposition->fresh(),
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
            return $parts[$indices[0]] ?? '';
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
        if ($startIndex === $endIndex) {
            return $parts[$startIndex] ?? '';
        }

        // 各パーツの位置を特定
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

        if ($startPos !== -1 && $endPos !== -1) {
            return trim(mb_substr($originalText, $startPos, $endPos - $startPos));
        }

        // フォールバック: 単純に連結
        return implode(' / ', array_slice($parts, $startIndex, $endIndex - $startIndex + 1));
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
            'undone_count' => 1,
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

        $this->attachSongToMapping($decomposition, $song);

        return $song;
    }

    /**
     * decompositionとtimestamp_song_mappingsを指定した楽曲に紐付ける
     */
    private function attachSongToMapping(TimestampDecomposition $decomposition, Song $song): void
    {
        $decomposition->update([
            'song_id' => $song->id,
            'updated_by' => Auth::id(),
        ]);

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
