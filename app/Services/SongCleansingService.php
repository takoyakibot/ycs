<?php

namespace App\Services;

use App\Helpers\QueryHelper;
use App\Models\NormalizationLog;
use App\Models\Song;
use App\Models\SongGroupReview;
use App\Models\TsItem;
use Illuminate\Support\Facades\DB;

class SongCleansingService
{
    /**
     * 同名異表記グループの表示件数上限
     */
    private const GROUP_LIMIT = 50;

    /**
     * フィルタ前の取得件数上限。レビュー済みグループがこれを超えて上位に集中すると
     * 表示件数が痩せる問題が再発しうる（根本解決にはSQL側でJOIN除外が必要）
     */
    private const PRE_FILTER_LIMIT = 500;

    public function __construct(private SongMergeService $songMergeService) {}

    /**
     * アーティスト名一括変換のプレビューを取得する
     *
     * 変換対象（artist = $from）の楽曲ごとに、変換後（title, $to）に一致する
     * 既存マスタがあるか判定し、リネームのみか統合が必要かを返す。
     */
    public function previewArtistRename(string $from, string $to): array
    {
        $plan = $this->buildArtistRenamePlan($from, $to);

        return [
            'plan' => $plan,
            'rename_count' => count(array_filter($plan, fn ($item) => $item['action'] === 'rename')),
            'merge_count' => count(array_filter($plan, fn ($item) => $item['action'] === 'merge')),
        ];
    }

    /**
     * アーティスト名を一括変換する
     *
     * 変換先に同一タイトルの既存マスタが存在する場合は、そちらへマージする
     * （紐付くタイムスタンプ・個別紐付けを移行し、変換元マスタを削除する）。
     * 存在しない場合は artist を変換後の名前に更新するだけとなる。
     */
    public function executeArtistRename(string $from, string $to, int $userId): array
    {
        return DB::transaction(function () use ($from, $to, $userId) {
            $songs = Song::where('artist', $from)->orderBy('title')->get();

            $renamed = [];
            $merged = [];

            foreach ($songs as $song) {
                $conflict = $this->findRenameConflict($song, $to);

                if ($conflict) {
                    $result = $this->songMergeService->merge($song->id, $conflict->id, $userId);
                    $merged[] = [
                        'title' => $song->title,
                        'source_song_id' => $song->id,
                        'target_song_id' => $conflict->id,
                        'affected_mappings' => $result['affected_mappings'],
                        'affected_ts_items' => $result['affected_ts_items'],
                        'affected_decompositions' => $result['affected_decompositions'],
                    ];
                } else {
                    $song->update(['artist' => $to]);
                    $renamed[] = [
                        'title' => $song->title,
                        'song_id' => $song->id,
                    ];
                }
            }

            NormalizationLog::log(
                $userId,
                NormalizationLog::ACTION_RENAME_ARTIST,
                NormalizationLog::TARGET_SONG,
                null,
                [
                    'from' => $from,
                    'to' => $to,
                    'renamed_count' => count($renamed),
                    'merged_count' => count($merged),
                ]
            );

            return [
                'renamed' => $renamed,
                'merged' => $merged,
            ];
        });
    }

    private function buildArtistRenamePlan(string $from, string $to): array
    {
        $songs = Song::where('artist', $from)->orderBy('title')->get();

        // 同一バッチ内の重複タイトルを検出するため、リネーム済みタイトルを追跡
        $renamedByNormalizedTitle = []; // normalized_title => song_id

        return $songs->map(function (Song $song) use ($to, &$renamedByNormalizedTitle) {
            $conflict = $this->findRenameConflict($song, $to);

            // 既存の変換先に競合がなくても、同一バッチ内で先にリネームされた曲と
            // normalized_title が一致する場合は、実行時にマージが発生する
            if (! $conflict && isset($renamedByNormalizedTitle[$song->normalized_title])) {
                return [
                    'song_id' => $song->id,
                    'title' => $song->title,
                    'action' => 'merge',
                    'conflict_song_id' => $renamedByNormalizedTitle[$song->normalized_title],
                ];
            }

            if (! $conflict) {
                $renamedByNormalizedTitle[$song->normalized_title] = $song->id;
            }

            return [
                'song_id' => $song->id,
                'title' => $song->title,
                'action' => $conflict ? 'merge' : 'rename',
                'conflict_song_id' => $conflict?->id,
            ];
        })->values()->toArray();
    }

    private function findRenameConflict(Song $song, string $to): ?Song
    {
        return Song::where('normalized_title', $song->normalized_title)
            ->where('artist', $to)
            ->where('id', '!=', $song->id)
            ->first();
    }

    /**
     * 同名異表記グループ（同じタイトルで複数名義のマスタが存在するグループ）を検出する
     *
     * @param  string  $filter  'active'（未処理のみ） | 'pending'（保留のみ）
     */
    public function findTitleGroups(string $search = '', string $filter = 'active'): array
    {
        $titleQuery = Song::selectRaw('normalized_title, COUNT(DISTINCT normalized_artist) as artist_count')
            ->whereNotNull('normalized_title')
            ->where('normalized_title', '!=', '')
            ->groupBy('normalized_title')
            ->having('artist_count', '>', 1)
            ->orderByDesc('artist_count')
            ->orderBy('normalized_title');

        if ($search !== '') {
            $escaped = QueryHelper::escapeLikeString($search);
            $titleQuery->where('title', 'LIKE', "%{$escaped}%");
        }

        $normalizedTitles = $titleQuery->limit(self::PRE_FILTER_LIMIT)->pluck('normalized_title');

        return $this->buildGroupsFromTitles($normalizedTitles, $filter);
    }

    /**
     * 重複楽曲のグループを検出する
     *
     * normalized_title が同じ楽曲をグループ化して返す。
     * SongGroupReview の判定結果でフィルタリングする。
     */
    public function findDuplicates(string $search = '', string $filter = 'active'): array
    {
        $titleQuery = Song::selectRaw('normalized_title, COUNT(*) as count')
            ->whereNotNull('normalized_title')
            ->where('normalized_title', '!=', '')
            ->groupBy('normalized_title')
            ->having('count', '>', 1)
            ->orderBy('count', 'desc')
            ->orderBy('normalized_title');

        if ($search !== '') {
            $escaped = QueryHelper::escapeLikeString($search);
            $titleQuery->where('title', 'LIKE', "%{$escaped}%");
        }

        $normalizedTitles = $titleQuery->limit(self::PRE_FILTER_LIMIT)->pluck('normalized_title');

        return $this->buildGroupsFromTitles($normalizedTitles, $filter);
    }

    private function buildGroupsFromTitles(\Illuminate\Support\Collection $normalizedTitles, string $filter): array
    {
        if ($normalizedTitles->isEmpty()) {
            return [];
        }

        $songs = Song::whereIn('normalized_title', $normalizedTitles)
            ->orderBy('artist')
            ->orderBy('title')
            ->get();

        $tsItemCounts = $this->fetchTsItemCounts($songs->pluck('id')->toArray());

        $grouped = $songs->groupBy('normalized_title');

        $groups = $normalizedTitles
            ->map(function ($normalizedTitle) use ($grouped, $tsItemCounts) {
                $songsInGroup = $grouped->get($normalizedTitle);
                if (! $songsInGroup) {
                    return null;
                }

                $sortedIds = $songsInGroup->pluck('id')->sort()->values()->all();

                return [
                    'normalized_title' => $normalizedTitle,
                    'song_ids_hash' => SongGroupReview::hashSongIds($sortedIds),
                    'songs' => $songsInGroup->map(fn (Song $song) => [
                        'id' => $song->id,
                        'title' => $song->title,
                        'artist' => $song->artist,
                        'ts_items_count' => $tsItemCounts->get($song->id, 0),
                    ])->values(),
                ];
            })
            ->filter()
            ->values();

        return $this->filterByReview($groups, $filter);
    }

    private function fetchTsItemCounts(array $songIds): \Illuminate\Support\Collection
    {
        return TsItem::selectRaw('song_id, COUNT(*) as count')
            ->whereIn('song_id', $songIds)
            ->groupBy('song_id')
            ->pluck('count', 'song_id');
    }

    private function filterByReview(\Illuminate\Support\Collection $groups, string $filter): array
    {
        $hashes = $groups->pluck('song_ids_hash')->toArray();
        $reviewedDecisions = SongGroupReview::whereIn('song_ids_hash', $hashes)
            ->pluck('decision', 'song_ids_hash');

        return $groups->filter(function ($group) use ($reviewedDecisions, $filter) {
            $decision = $reviewedDecisions->get($group['song_ids_hash']);

            return $filter === 'pending'
                ? $decision === SongGroupReview::DECISION_PENDING
                : $decision === null;
        })->take(self::GROUP_LIMIT)->values()->toArray();
    }

    /**
     * 同名異表記グループを「別の曲」または「保留」として記録する
     */
    public function reviewTitleGroup(string $normalizedTitle, array $songIds, string $decision, int $userId): SongGroupReview
    {
        $hash = SongGroupReview::hashSongIds($songIds);

        // クライアントから送られた normalized_title を信用せず、実際の楽曲から取得する
        $actualNormalizedTitle = Song::whereIn('id', $songIds)->value('normalized_title') ?? $normalizedTitle;

        $review = SongGroupReview::updateOrCreate(
            ['song_ids_hash' => $hash],
            [
                'normalized_title' => $actualNormalizedTitle,
                'song_ids' => $songIds,
                'decision' => $decision,
                'created_by' => $userId,
            ]
        );

        NormalizationLog::log(
            $userId,
            NormalizationLog::ACTION_REVIEW_SONG_GROUP,
            NormalizationLog::TARGET_SONG,
            null,
            [
                'normalized_title' => $normalizedTitle,
                'song_ids' => $songIds,
                'decision' => $decision,
            ]
        );

        return $review;
    }
}
