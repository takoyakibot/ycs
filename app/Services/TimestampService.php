<?php

namespace App\Services;

use App\Helpers\CharacterCategorizer;
use App\Helpers\QueryHelper;
use App\Helpers\TextNormalizer;
use App\Helpers\ValidationHelper;
use App\Models\Channel;
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
                'timestamp_song_mappings.is_manual',
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

        // 検索条件（スペース区切りでAND検索、正規化して類似文字を吸収）
        if ($search) {
            $keywords = QueryHelper::splitSearchKeywords($search);
            foreach ($keywords as $keyword) {
                $normalizedKeyword = TextNormalizer::normalize($keyword);
                $escaped = QueryHelper::escapeLikeString($normalizedKeyword);
                $query->where('ts_items.normalized_text', 'like', "%{$escaped}%");
            }
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
                    'is_manual' => (bool) $item->is_manual,
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

        // 検索条件（スペース区切りでAND検索、正規化して類似文字を吸収）
        if ($search) {
            $keywords = QueryHelper::splitSearchKeywords($search);
            foreach ($keywords as $keyword) {
                $normalizedKeyword = TextNormalizer::normalize($keyword);
                $escaped = QueryHelper::escapeLikeString($normalizedKeyword);
                $query->where('ts_items.normalized_text', 'like', "%{$escaped}%");
            }
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
     * チャンネルのタイムスタンプからランダムに1件取得
     *
     * @param  int  $perPage  1ページあたりの件数（ページ番号計算用）
     * @return array|null タイムスタンプデータ（見つからない場合はnull）
     */
    public function getRandomTimestamp(Channel $channel, int $perPage = 50): ?array
    {
        // ベースクエリ: ts_itemsとtimestamp_song_mappings、songsをLEFT JOIN
        $query = TsItem::with(['archive'])
            ->leftJoin('timestamp_song_mappings', 'ts_items.normalized_text', '=', 'timestamp_song_mappings.normalized_text')
            ->leftJoin('songs', 'timestamp_song_mappings.song_id', '=', 'songs.id')
            ->select(
                'ts_items.*',
                'timestamp_song_mappings.id as mapping_id',
                'timestamp_song_mappings.song_id',
                'timestamp_song_mappings.is_not_song',
                'timestamp_song_mappings.is_manual',
                'songs.title as song_title',
                'songs.artist as song_artist',
                'songs.spotify_track_id',
                'songs.spotify_data'
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
        $query->where(function ($q) {
            $q->whereNull('timestamp_song_mappings.id')
                ->orWhere('timestamp_song_mappings.is_not_song', false);
        });

        // ランダムに1件取得
        $item = $query->inRandomOrder()->first();

        if (! $item) {
            return null;
        }

        // 選ばれたアイテムのソート順での位置を計算（ページ番号算出用）
        $sortKey = $item->song_title ?? $item->text;
        $position = $this->calculateItemPosition($channel, $sortKey, $item->id);
        $page = (int) ceil($position / $perPage);

        // 同じ動画内の次のタイムスタンプを取得（自動再抽選用）
        $nextTsNum = $this->getNextTimestampInVideo($item->video_id, $item->ts_num);

        // spotify_dataから楽曲の長さを取得（ミリ秒）
        $songDurationMs = null;
        if ($item->spotify_data) {
            $spotifyData = is_string($item->spotify_data)
                ? json_decode($item->spotify_data, true)
                : $item->spotify_data;
            $songDurationMs = $spotifyData['duration_ms'] ?? null;
        }

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
                    'duration_ms' => $songDurationMs,
                ] : null,
                'is_not_song' => (bool) $item->is_not_song,
                'is_manual' => (bool) $item->is_manual,
            ] : null,
            'page' => max(1, $page),
            'next_ts_num' => $nextTsNum,
        ];
    }

    /**
     * 同じ動画内の次のタイムスタンプ（秒数）を取得
     */
    private function getNextTimestampInVideo(string $videoId, ?int $currentTsNum): ?int
    {
        if ($currentTsNum === null) {
            return null;
        }

        $nextItem = TsItem::where('video_id', $videoId)
            ->where('ts_num', '>', $currentTsNum)
            ->where('is_display', 1)
            ->whereNotNull('text')
            ->where('text', '!=', '')
            ->orderBy('ts_num', 'asc')
            ->first(['ts_num']);

        return $nextItem?->ts_num;
    }

    /**
     * 同じ動画内の次の楽曲タイムスタンプを取得（フル情報）
     *
     * @param  Channel  $channel  チャンネル
     * @param  string  $videoId  動画ID
     * @param  int  $currentTsNum  現在のタイムスタンプ秒数
     * @return array|null タイムスタンプデータ（見つからない場合はnull）
     */
    public function getNextTimestampInArchive(Channel $channel, string $videoId, int $currentTsNum): ?array
    {
        // 同じ動画内で、現在のタイムスタンプより後のものを取得
        // チャンネルに属する動画のみを対象とする
        $item = TsItem::with(['archive'])
            ->leftJoin('timestamp_song_mappings', 'ts_items.normalized_text', '=', 'timestamp_song_mappings.normalized_text')
            ->leftJoin('songs', 'timestamp_song_mappings.song_id', '=', 'songs.id')
            ->select(
                'ts_items.*',
                'timestamp_song_mappings.id as mapping_id',
                'timestamp_song_mappings.song_id',
                'timestamp_song_mappings.is_not_song',
                'timestamp_song_mappings.is_manual',
                'songs.title as song_title',
                'songs.artist as song_artist',
                'songs.spotify_track_id',
                'songs.spotify_data'
            )
            ->whereHas('archive', function ($q) use ($channel) {
                $q->where('channel_id', $channel->channel_id)
                    ->where('is_display', 1);
            })
            ->where('ts_items.video_id', $videoId)
            ->where('ts_items.ts_num', '>', $currentTsNum)
            ->whereNotNull('ts_items.text')
            ->where('ts_items.text', '!=', '')
            ->whereNotNull('ts_items.normalized_text')
            ->where('ts_items.is_display', 1)
            // 「楽曲ではない」を除外
            ->where(function ($q) {
                $q->whereNull('timestamp_song_mappings.id')
                    ->orWhere('timestamp_song_mappings.is_not_song', false);
            })
            ->orderBy('ts_items.ts_num', 'asc')
            ->first();

        if (! $item) {
            return null;
        }

        // 同じ動画内の次のタイムスタンプを取得
        $nextTsNum = $this->getNextTimestampInVideo($videoId, $item->ts_num);

        // spotify_dataから楽曲の長さを取得（ミリ秒）
        $songDurationMs = null;
        if ($item->spotify_data) {
            $spotifyData = is_string($item->spotify_data)
                ? json_decode($item->spotify_data, true)
                : $item->spotify_data;
            $songDurationMs = $spotifyData['duration_ms'] ?? null;
        }

        // アーカイブ内の最後の楽曲かどうかを判定
        $isLastInArchive = $nextTsNum === null;

        return [
            'id' => $item->id,
            'ts_text' => $item->ts_text,
            'ts_num' => $item->ts_num,
            'text' => $item->text,
            'video_id' => $item->video_id,
            'archive' => $item->archive ? [
                'title' => $item->archive->title,
                'published_at' => $item->archive->published_at,
            ] : null,
            'mapping' => $item->mapping_id ? [
                'song' => $item->song_id ? [
                    'title' => $item->song_title,
                    'artist' => $item->song_artist,
                    'spotify_track_id' => ValidationHelper::validateSpotifyTrackId($item->spotify_track_id),
                    'duration_ms' => $songDurationMs,
                ] : null,
                'is_not_song' => (bool) $item->is_not_song,
                'is_manual' => (bool) $item->is_manual,
            ] : null,
            'next_ts_num' => $nextTsNum,
            'is_last_in_archive' => $isLastInArchive,
        ];
    }

    /**
     * アイテムのソート順での位置を計算
     */
    private function calculateItemPosition(Channel $channel, string $sortKey, string $itemId): int
    {
        // ソートキーより前にあるアイテム数をカウント
        $countBefore = TsItem::query()
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
            })
            ->whereRaw('COALESCE(songs.title, ts_items.text) < ?', [$sortKey])
            ->count();

        // 同じソートキーを持つアイテムの中での位置も考慮
        $countSameKey = TsItem::query()
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
            })
            ->whereRaw('COALESCE(songs.title, ts_items.text) = ?', [$sortKey])
            ->where('ts_items.id', '<', $itemId)
            ->count();

        return $countBefore + $countSameKey + 1;
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
            // ts_itemsと複合キー(video_id, ts_text, ts_num)でJOINして報告のあるts_item.idを取得
            return TsItem::whereIn('ts_items.id', $tsItemIds)
                ->join('timestamp_reports', function ($join) {
                    $join->on('ts_items.video_id', '=', 'timestamp_reports.video_id')
                        ->on('ts_items.ts_text', '=', 'timestamp_reports.ts_text')
                        ->on('ts_items.ts_num', '=', 'timestamp_reports.ts_num');
                })
                ->where('timestamp_reports.status', 'pending')
                ->pluck('ts_items.id')
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
