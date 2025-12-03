<?php

namespace App\Services;

use App\Helpers\CharacterCategorizer;
use App\Helpers\QueryHelper;
use App\Helpers\ValidationHelper;
use App\Models\Channel;
use App\Models\TimestampReport;
use App\Models\TsItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TimestampService
{
    /**
     * チャンネルのタイムスタンプを取得（マッピング情報付き）
     *
     * DBレベルでフィルタリング・ソート・ページネーションを実行
     *
     * @return array{
     *     data: array,
     *     current_page: int,
     *     last_page: int,
     *     per_page: int,
     *     total: int,
     *     available_indexes: array
     * }
     */
    public function getTimestampsWithMapping(
        Channel $channel,
        int $perPage = 50,
        int $currentPage = 1,
        string $search = '',
        string $index = ''
    ): array {
        // ベースクエリ: ts_itemsとtimestamp_song_mappings、songsをLEFT JOIN
        $query = TsItem::with(['archive'])
            ->leftJoin('timestamp_song_mappings', 'ts_items.normalized_text', '=', 'timestamp_song_mappings.normalized_text')
            ->leftJoin('songs', 'timestamp_song_mappings.song_id', '=', 'songs.id')
            ->select(
                'ts_items.*',
                'timestamp_song_mappings.id as mapping_id',
                'timestamp_song_mappings.song_id',
                'timestamp_song_mappings.is_not_song',
                'songs.title as song_title',
                'songs.artist as song_artist',
                'songs.spotify_track_id'
            )
            ->whereHas('archive', function ($q) use ($channel) {
                $q->where('channel_id', $channel->channel_id)
                    ->where('is_display', 1);
            })
            ->whereNotNull('ts_items.text')
            ->where('ts_items.text', '!=', '')
            ->whereNotNull('ts_items.normalized_text')
            ->where('ts_items.is_display', 1);

        // 「楽曲ではない」を除外
        // - マッピングが存在しない（未分類）: 表示
        // - マッピングが存在してis_not_song=false（楽曲）: 表示
        // - マッピングが存在してis_not_song=true（楽曲でない）: 除外
        $query->where(function ($q) {
            $q->whereNull('timestamp_song_mappings.id')
                ->orWhere('timestamp_song_mappings.is_not_song', false);
        });

        // 検索条件
        if ($search) {
            $escapedSearch = QueryHelper::escapeLikeString($search);
            $query->where('ts_items.text', 'like', "%{$escapedSearch}%");
        }

        // 頭文字インデックスでフィルタリング
        if ($index) {
            $this->applyIndexFilter($query, $index);
        }

        // 楽曲名順でソート（楽曲名がなければタイムスタンプテキストを使用）
        $query->orderByRaw('COALESCE(songs.title, ts_items.text) ASC');

        // 利用可能な頭文字カテゴリを取得（フィルタリング前のベースクエリで）
        $availableIndexes = $this->fetchAvailableIndexes($channel, $search);

        // DBページネーション
        $paginated = $query->paginate($perPage, ['*'], 'page', $currentPage);

        // 報告情報を取得
        $tsItemIds = $paginated->getCollection()->pluck('id')->toArray();
        $reportedTsItemIds = $this->fetchReportedIds($tsItemIds);

        // 各タイムスタンプを整形
        $items = $paginated->getCollection()->map(function ($item) use ($reportedTsItemIds) {
            return [
                'id' => $item->id,
                'ts_text' => $item->ts_text,
                'ts_num' => $item->ts_num,
                'text' => $item->text,
                'video_id' => $item->video_id,
                'archive' => [
                    'title' => $item->archive->title,
                    'published_at' => $item->archive->published_at,
                ],
                'mapping' => $item->mapping_id ? [
                    'song' => $item->song_id ? [
                        'title' => $item->song_title,
                        'artist' => $item->song_artist,
                        'spotify_track_id' => ValidationHelper::validateSpotifyTrackId($item->spotify_track_id),
                    ] : null,
                    'is_not_song' => (bool) $item->is_not_song,
                ] : null,
                'has_pending_report' => in_array($item->id, $reportedTsItemIds),
            ];
        });

        return [
            'data' => $items->values()->toArray(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'per_page' => $paginated->perPage(),
            'total' => $paginated->total(),
            'available_indexes' => $availableIndexes,
        ];
    }

    /**
     * 頭文字インデックスでフィルタリング
     *
     * @param  Builder  $query  クエリビルダー
     * @param  string  $index  頭文字インデックス（カテゴリ名）
     */
    private function applyIndexFilter(Builder $query, string $index): void
    {
        $chars = CharacterCategorizer::getCharsForCategory($index);

        if (CharacterCategorizer::isOtherCategory($index)) {
            // 「その他」カテゴリ: 既知のカテゴリに属さない文字（主に漢字）
            $driver = DB::getDriverName();

            if ($driver === 'mysql') {
                // MySQL: REGEXPで効率的にフィルタリング
                // 既知カテゴリ（アルファベット、数字、ひらがな、カタカナ）以外を抽出
                $pattern = '^[A-Za-z0-9あ-んア-ンー]';
                $query->where(function ($q) use ($pattern) {
                    $q->where(function ($subQ) use ($pattern) {
                        // 楽曲名がある場合
                        $subQ->whereNotNull('songs.title')
                            ->whereRaw('songs.title NOT REGEXP ?', [$pattern]);
                    })->orWhere(function ($subQ) use ($pattern) {
                        // 楽曲名がない場合、タイムスタンプテキストで判定
                        $subQ->whereNull('songs.title')
                            ->whereRaw('ts_items.text NOT REGEXP ?', [$pattern]);
                    });
                });
            } else {
                // SQLite: NOT LIKEアプローチ（互換性優先）
                $allKnownChars = [];
                foreach (CharacterCategorizer::getAllCategories() as $category) {
                    if (! CharacterCategorizer::isOtherCategory($category)) {
                        $allKnownChars = array_merge($allKnownChars, CharacterCategorizer::getCharsForCategory($category));
                    }
                }

                $query->where(function ($q) use ($allKnownChars) {
                    $q->where(function ($subQ) use ($allKnownChars) {
                        // 楽曲名がある場合
                        $subQ->whereNotNull('songs.title');
                        foreach ($allKnownChars as $char) {
                            $escapedChar = QueryHelper::escapeLikeString($char);
                            $subQ->where('songs.title', 'not like', $escapedChar.'%');
                        }
                    })->orWhere(function ($subQ) use ($allKnownChars) {
                        // 楽曲名がない場合、タイムスタンプテキストで判定
                        $subQ->whereNull('songs.title');
                        foreach ($allKnownChars as $char) {
                            $escapedChar = QueryHelper::escapeLikeString($char);
                            $subQ->where('ts_items.text', 'not like', $escapedChar.'%');
                        }
                    });
                });
            }
        } elseif (! empty($chars)) {
            // 通常のカテゴリ: 指定された文字で始まるものをフィルタ
            $query->where(function ($q) use ($chars) {
                $q->where(function ($subQ) use ($chars) {
                    // 楽曲名がある場合
                    $subQ->whereNotNull('songs.title');
                    $subQ->where(function ($innerQ) use ($chars) {
                        foreach ($chars as $char) {
                            $escapedChar = QueryHelper::escapeLikeString($char);
                            $innerQ->orWhere('songs.title', 'like', $escapedChar.'%');
                        }
                    });
                })->orWhere(function ($subQ) use ($chars) {
                    // 楽曲名がない場合、タイムスタンプテキストで判定
                    $subQ->whereNull('songs.title');
                    $subQ->where(function ($innerQ) use ($chars) {
                        foreach ($chars as $char) {
                            $escapedChar = QueryHelper::escapeLikeString($char);
                            $innerQ->orWhere('ts_items.text', 'like', $escapedChar.'%');
                        }
                    });
                });
            });
        }
    }

    /**
     * 利用可能な頭文字カテゴリを取得
     */
    private function fetchAvailableIndexes(Channel $channel, string $search): array
    {
        // ベースクエリを構築（頭文字抽出用）
        $query = TsItem::query()
            ->leftJoin('timestamp_song_mappings', 'ts_items.normalized_text', '=', 'timestamp_song_mappings.normalized_text')
            ->leftJoin('songs', 'timestamp_song_mappings.song_id', '=', 'songs.id')
            ->whereHas('archive', function ($q) use ($channel) {
                $q->where('channel_id', $channel->channel_id)
                    ->where('is_display', 1);
            })
            ->whereNotNull('ts_items.text')
            ->where('ts_items.text', '!=', '')
            ->whereNotNull('ts_items.normalized_text')
            ->where('ts_items.is_display', 1)
            ->where(function ($q) {
                $q->whereNull('timestamp_song_mappings.id')
                    ->orWhere('timestamp_song_mappings.is_not_song', false);
            });

        // 検索条件
        if ($search) {
            $escapedSearch = QueryHelper::escapeLikeString($search);
            $query->where('ts_items.text', 'like', "%{$escapedSearch}%");
        }

        // 頭文字を取得（楽曲名優先、なければタイムスタンプテキスト）
        $firstChars = $query
            ->selectRaw('DISTINCT SUBSTRING(COALESCE(songs.title, ts_items.text), 1, 1) as first_char')
            ->pluck('first_char')
            ->filter()
            ->toArray();

        // 頭文字をカテゴリに変換
        $availableIndexes = [];
        foreach ($firstChars as $char) {
            $category = CharacterCategorizer::categorize($char);
            if ($category && ! in_array($category, $availableIndexes)) {
                $availableIndexes[] = $category;
            }
        }

        return $availableIndexes;
    }

    /**
     * 未解決の報告があるタイムスタンプIDを取得
     */
    private function fetchReportedIds(array $tsItemIds): array
    {
        if (empty($tsItemIds)) {
            return [];
        }

        try {
            return TimestampReport::whereIn('ts_item_id', $tsItemIds)
                ->where('status', 'pending')
                ->pluck('ts_item_id')
                ->unique()
                ->toArray();
        } catch (\Exception $e) {
            Log::error('Failed to fetch timestamp reports', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
