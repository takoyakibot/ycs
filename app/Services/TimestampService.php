<?php

namespace App\Services;

use App\Helpers\CharacterCategorizer;
use App\Helpers\QueryHelper;
use App\Helpers\TextNormalizer;
use App\Helpers\ValidationHelper;
use App\Models\Channel;
use App\Models\Song;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TimestampService
{
    private const SONG_TITLE_COALESCE = 'COALESCE(individual_songs.title, songs.title, ts_items.text)';

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
        string $index = '',
        string $publishedFrom = '',
        string $publishedTo = ''
    ): array {
        // ベースクエリ: ts_itemsとtimestamp_song_mappings、songsをLEFT JOIN
        $query = TsItem::with(['archive'])
            ->leftJoin('timestamp_song_mappings', 'ts_items.normalized_text', '=', 'timestamp_song_mappings.normalized_text')
            ->leftJoin('songs', 'timestamp_song_mappings.song_id', '=', 'songs.id')
            ->leftJoin('songs as individual_songs', 'ts_items.song_id', '=', 'individual_songs.id')
            ->select(
                'ts_items.*',
                'timestamp_song_mappings.id as mapping_id',
                'timestamp_song_mappings.song_id',
                'timestamp_song_mappings.is_not_song',
                'timestamp_song_mappings.is_manual',
                'timestamp_song_mappings.status as mapping_status',
                'songs.title as song_title',
                'songs.artist as song_artist',
                'songs.spotify_track_id',
                'ts_items.song_id as individual_song_id'
            )
            ->whereHas('archive', function ($q) use ($channel) {
                $q->where('channel_id', $channel->channel_id)
                    ->where('is_display', 1);
            })
            ->whereNotNull('ts_items.text')
            ->where('ts_items.text', '!=', '')
            ->whereNotNull('ts_items.normalized_text')
            ->where('ts_items.is_display', 1);

        $this->applyIsNotSongFilter($query);

        // 検索・頭文字インデックス・公開日で絞り込み
        $this->applyFilters($query, $search, $index, $publishedFrom, $publishedTo);

        // 楽曲名順でソート（個別マッピング曲名 > テキストマッピング曲名 > TSテキスト）
        $query->orderByRaw(self::SONG_TITLE_COALESCE.' ASC');

        // 利用可能な頭文字カテゴリを取得（フィルタリング前のベースクエリで）
        $availableIndexes = $this->fetchAvailableIndexes($channel, $search, $publishedFrom, $publishedTo);

        // DBページネーション
        $paginated = $query->paginate($perPage, ['*'], 'page', $currentPage);

        // 報告情報を取得
        $tsItemIds = $paginated->getCollection()->pluck('id')->toArray();
        $reportedTsItemIds = $this->fetchReportedIds($tsItemIds);

        // 個別マッピング（ts_items.song_id）の楽曲情報を一括取得（#724）
        $individualSongIds = $paginated->getCollection()
            ->pluck('individual_song_id')->filter()->unique()->values()->toArray();
        $individualSongs = ! empty($individualSongIds)
            ? Song::whereIn('id', $individualSongIds)->get()->keyBy('id')
            : collect();

        // 各タイムスタンプを整形
        $items = $paginated->getCollection()->map(function ($item) use ($reportedTsItemIds, $individualSongs) {
            $individualSong = $item->individual_song_id
                ? ($individualSongs[$item->individual_song_id] ?? null) : null;
            $songInfo = $this->buildSongInfo($individualSong, $item);

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
                'mapping' => $this->buildMappingResponse($individualSong, $item, $songInfo),
                'is_individual_mapping' => $individualSong !== null,
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
     * 検索・頭文字インデックスの絞り込み条件を適用
     *
     * 一覧・ガチャ（ランダム取得）・ページ位置計算で同じ条件を使うため共通化している。
     *
     * @param  string  $search  検索キーワード（スペース区切りでAND検索）
     * @param  string  $index  頭文字インデックス（カテゴリ名）
     * @param  string  $publishedFrom  アーカイブ公開日の開始日（Y-m-d、空文字は指定なし）
     * @param  string  $publishedTo  アーカイブ公開日の終了日（Y-m-d、空文字は指定なし）
     */
    private function applyFilters(Builder $query, string $search, string $index, string $publishedFrom = '', string $publishedTo = ''): void
    {
        if ($search) {
            $keywords = QueryHelper::splitSearchKeywords($search);
            foreach ($keywords as $keyword) {
                ['term' => $term, 'exclude' => $exclude] = QueryHelper::parseSearchTerm($keyword);
                $normalizedKeyword = TextNormalizer::normalize($term);
                $escaped = QueryHelper::escapeLikeString($normalizedKeyword);
                if ($exclude) {
                    $query->where('ts_items.normalized_text', 'not like', "%{$escaped}%");
                } else {
                    $query->where('ts_items.normalized_text', 'like', "%{$escaped}%");
                }
            }
        }

        // 頭文字インデックスでフィルタリング
        if ($index) {
            $this->applyIndexFilter($query, $index);
        }

        // アーカイブ公開日でフィルタリング
        $this->applyPublishedDateFilter($query, $publishedFrom, $publishedTo);
    }

    /**
     * アーカイブ公開日（from/to）でフィルタリング
     *
     * @param  string  $publishedFrom  開始日（Y-m-d、空文字は指定なし）
     * @param  string  $publishedTo  終了日（Y-m-d、空文字は指定なし）
     */
    private function applyPublishedDateFilter(Builder $query, string $publishedFrom, string $publishedTo): void
    {
        if ($publishedFrom === '' && $publishedTo === '') {
            return;
        }

        $query->whereHas('archive', function ($q) use ($publishedFrom, $publishedTo) {
            if ($publishedFrom !== '') {
                $q->whereDate('published_at', '>=', $publishedFrom);
            }
            if ($publishedTo !== '') {
                $q->whereDate('published_at', '<=', $publishedTo);
            }
        });
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

        $coalesce = self::SONG_TITLE_COALESCE;

        if (CharacterCategorizer::isOtherCategory($index)) {
            // 「その他」カテゴリ: 既知のカテゴリに属さない文字（主に漢字）
            $driver = DB::getDriverName();

            if ($driver === 'mysql') {
                $pattern = '^[A-Za-z0-9あ-んア-ンー]';
                $query->whereRaw("{$coalesce} NOT REGEXP ?", [$pattern]);
            } else {
                // SQLite: NOT LIKEアプローチ（互換性優先）
                $allKnownChars = [];
                foreach (CharacterCategorizer::getAllCategories() as $category) {
                    if (! CharacterCategorizer::isOtherCategory($category)) {
                        $allKnownChars = array_merge($allKnownChars, CharacterCategorizer::getCharsForCategory($category));
                    }
                }

                foreach ($allKnownChars as $char) {
                    $escapedChar = QueryHelper::escapeLikeString($char);
                    $query->whereRaw("{$coalesce} NOT LIKE ?", [$escapedChar.'%']);
                }
            }
        } elseif (! empty($chars)) {
            // 通常のカテゴリ: 指定された文字で始まるものをフィルタ
            $query->where(function ($q) use ($chars, $coalesce) {
                foreach ($chars as $char) {
                    $escapedChar = QueryHelper::escapeLikeString($char);
                    $q->orWhereRaw("{$coalesce} LIKE ?", [$escapedChar.'%']);
                }
            });
        }
    }

    /**
     * 利用可能な頭文字カテゴリを取得
     */
    private function fetchAvailableIndexes(Channel $channel, string $search, string $publishedFrom = '', string $publishedTo = ''): array
    {
        // ベースクエリを構築（頭文字抽出用）
        $query = TsItem::query()
            ->leftJoin('timestamp_song_mappings', 'ts_items.normalized_text', '=', 'timestamp_song_mappings.normalized_text')
            ->leftJoin('songs', 'timestamp_song_mappings.song_id', '=', 'songs.id')
            ->leftJoin('songs as individual_songs', 'ts_items.song_id', '=', 'individual_songs.id')
            ->whereHas('archive', function ($q) use ($channel) {
                $q->where('channel_id', $channel->channel_id)
                    ->where('is_display', 1);
            })
            ->whereNotNull('ts_items.text')
            ->where('ts_items.text', '!=', '')
            ->whereNotNull('ts_items.normalized_text')
            ->where('ts_items.is_display', 1);

        $this->applyIsNotSongFilter($query);

        // 検索・公開日で絞り込み（頭文字インデックスはカテゴリ抽出対象のため適用しない）
        $this->applyFilters($query, $search, '', $publishedFrom, $publishedTo);

        // 頭文字を取得（個別マッピング曲名 > テキストマッピング曲名 > TSテキスト）
        $firstChars = $query
            ->selectRaw('DISTINCT SUBSTRING('.self::SONG_TITLE_COALESCE.', 1, 1) as first_char')
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
     * @param  string  $search  検索キーワード（一覧と同じ条件で絞り込む）
     * @param  string  $index  頭文字インデックス（一覧と同じ条件で絞り込む）
     * @param  string  $publishedFrom  アーカイブ公開日の開始日（一覧と同じ条件で絞り込む）
     * @param  string  $publishedTo  アーカイブ公開日の終了日（一覧と同じ条件で絞り込む）
     * @return array|null タイムスタンプデータ（見つからない場合はnull）
     */
    public function getRandomTimestamp(
        Channel $channel,
        int $perPage = 50,
        ?string $excludeVideoId = null,
        string $search = '',
        string $index = '',
        string $publishedFrom = '',
        string $publishedTo = ''
    ): ?array {
        // ベースクエリ: ts_itemsとtimestamp_song_mappings、songsをLEFT JOIN
        $query = TsItem::with(['archive'])
            ->leftJoin('timestamp_song_mappings', 'ts_items.normalized_text', '=', 'timestamp_song_mappings.normalized_text')
            ->leftJoin('songs', 'timestamp_song_mappings.song_id', '=', 'songs.id')
            ->leftJoin('songs as individual_songs', 'ts_items.song_id', '=', 'individual_songs.id')
            ->select(
                'ts_items.*',
                'timestamp_song_mappings.id as mapping_id',
                'timestamp_song_mappings.song_id',
                'timestamp_song_mappings.is_not_song',
                'timestamp_song_mappings.is_manual',
                'timestamp_song_mappings.status as mapping_status',
                'songs.title as song_title',
                'songs.artist as song_artist',
                'songs.spotify_track_id',
                'songs.spotify_data',
                'ts_items.song_id as individual_song_id'
            )
            ->whereHas('archive', function ($q) use ($channel) {
                $q->where('channel_id', $channel->channel_id)
                    ->where('is_display', 1);
            })
            ->whereNotNull('ts_items.text')
            ->where('ts_items.text', '!=', '')
            ->whereNotNull('ts_items.normalized_text')
            ->where('ts_items.is_display', 1);

        $this->applyIsNotSongFilter($query);

        // 一覧と同じ検索・頭文字インデックス・公開日の条件で絞り込む
        $this->applyFilters($query, $search, $index, $publishedFrom, $publishedTo);

        // 直前のアーカイブを除外してランダムに1件取得（同じアーカイブの連続再生を防止）
        $item = null;
        if ($excludeVideoId) {
            $item = (clone $query)->where('ts_items.video_id', '!=', $excludeVideoId)
                ->inRandomOrder()->first();

            \Log::debug('getRandomTimestamp', [
                'excludeVideoId' => $excludeVideoId,
                'excludeQueryFound' => $item !== null,
                'selectedVideoId' => $item?->video_id,
            ]);
        }

        // 除外条件で見つからない場合（アーカイブが1つしかない等）はフォールバック
        if (! $item) {
            \Log::debug('getRandomTimestamp fallback executed', [
                'excludeVideoId' => $excludeVideoId,
            ]);
            $item = $query->inRandomOrder()->first();
        }

        if (! $item) {
            return null;
        }

        // 個別マッピング（ts_items.song_id）の楽曲情報を取得（#724）
        $individualSong = $item->individual_song_id
            ? Song::find($item->individual_song_id) : null;

        // 選ばれたアイテムのソート順での位置を計算（ページ番号算出用）
        // 一覧側も同じ絞り込みが効いているため、同条件でカウントしないとページがずれる
        $sortKey = $individualSong ? $individualSong->title : ($item->song_title ?? $item->text);
        $position = $this->calculateItemPosition($channel, $sortKey, $item->id, $search, $index, $publishedFrom, $publishedTo);
        $page = (int) ceil($position / $perPage);

        // 同じ動画内の次のタイムスタンプを取得（自動再抽選用）
        $nextTsNum = $this->getNextTimestampInVideo($item->video_id, $item->ts_num);

        $songInfo = $this->buildSongInfo($individualSong, $item, true);

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
            'mapping' => $this->buildMappingResponse($individualSong, $item, $songInfo),
            'is_individual_mapping' => $individualSong !== null,
            'page' => max(1, $page),
            'next_ts_num' => $nextTsNum,
        ];
    }

    /**
     * 同じ動画内の次の楽曲タイムスタンプ（秒数）を取得
     *
     * 抽出条件はgetNextTimestampInArchive()と揃える。
     * 条件がずれていると「next_ts_numはあるのに次の楽曲は取得できない」状態になり、
     * 表示更新の監視が行き止まりになる。
     */
    private function getNextTimestampInVideo(string $videoId, ?int $currentTsNum): ?int
    {
        if ($currentTsNum === null) {
            return null;
        }

        $query = TsItem::query()
            ->leftJoin('timestamp_song_mappings', 'ts_items.normalized_text', '=', 'timestamp_song_mappings.normalized_text')
            ->where('ts_items.video_id', $videoId)
            ->where('ts_items.ts_num', '>', $currentTsNum)
            ->where('ts_items.is_display', 1)
            ->whereNotNull('ts_items.text')
            ->where('ts_items.text', '!=', '')
            ->whereNotNull('ts_items.normalized_text');

        $this->applyIsNotSongFilter($query);

        $nextItem = $query->orderBy('ts_items.ts_num', 'asc')
            ->first(['ts_items.ts_num']);

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
        $query = TsItem::with(['archive'])
            ->leftJoin('timestamp_song_mappings', 'ts_items.normalized_text', '=', 'timestamp_song_mappings.normalized_text')
            ->leftJoin('songs', 'timestamp_song_mappings.song_id', '=', 'songs.id')
            ->select(
                'ts_items.*',
                'timestamp_song_mappings.id as mapping_id',
                'timestamp_song_mappings.song_id',
                'timestamp_song_mappings.is_not_song',
                'timestamp_song_mappings.is_manual',
                'timestamp_song_mappings.status as mapping_status',
                'songs.title as song_title',
                'songs.artist as song_artist',
                'songs.spotify_track_id',
                'songs.spotify_data',
                'ts_items.song_id as individual_song_id'
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
            ->where('ts_items.is_display', 1);

        $this->applyIsNotSongFilter($query);

        $item = $query->orderBy('ts_items.ts_num', 'asc')
            ->first();

        if (! $item) {
            return null;
        }

        // 個別マッピング（ts_items.song_id）の楽曲情報を取得（#724）
        $individualSong = $item->individual_song_id
            ? Song::find($item->individual_song_id) : null;

        // 同じ動画内の次のタイムスタンプを取得
        $nextTsNum = $this->getNextTimestampInVideo($videoId, $item->ts_num);

        // アーカイブ内の最後の楽曲かどうかを判定
        $isLastInArchive = $nextTsNum === null;

        $songInfo = $this->buildSongInfo($individualSong, $item, true);

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
            'mapping' => $this->buildMappingResponse($individualSong, $item, $songInfo),
            'is_individual_mapping' => $individualSong !== null,
            'next_ts_num' => $nextTsNum,
            'is_last_in_archive' => $isLastInArchive,
        ];
    }

    /**
     * アイテムのソート順での位置を計算
     */
    private function calculateItemPosition(
        Channel $channel,
        string $sortKey,
        string $itemId,
        string $search = '',
        string $index = '',
        string $publishedFrom = '',
        string $publishedTo = ''
    ): int {
        $coalesce = self::SONG_TITLE_COALESCE;

        // ソートキーより前にあるアイテム数をカウント
        $countBeforeQuery = TsItem::query()
            ->leftJoin('timestamp_song_mappings', 'ts_items.normalized_text', '=', 'timestamp_song_mappings.normalized_text')
            ->leftJoin('songs', 'timestamp_song_mappings.song_id', '=', 'songs.id')
            ->leftJoin('songs as individual_songs', 'ts_items.song_id', '=', 'individual_songs.id')
            ->whereHas('archive', function ($q) use ($channel) {
                $q->where('channel_id', $channel->channel_id)
                    ->where('is_display', 1);
            })
            ->whereNotNull('ts_items.text')
            ->where('ts_items.text', '!=', '')
            ->whereNotNull('ts_items.normalized_text')
            ->where('ts_items.is_display', 1);
        $this->applyIsNotSongFilter($countBeforeQuery);
        $countBeforeQuery->whereRaw("{$coalesce} < ?", [$sortKey]);
        $this->applyFilters($countBeforeQuery, $search, $index, $publishedFrom, $publishedTo);
        $countBefore = $countBeforeQuery->count();

        // 同じソートキーを持つアイテムの中での位置も考慮
        $countSameKeyQuery = TsItem::query()
            ->leftJoin('timestamp_song_mappings', 'ts_items.normalized_text', '=', 'timestamp_song_mappings.normalized_text')
            ->leftJoin('songs', 'timestamp_song_mappings.song_id', '=', 'songs.id')
            ->leftJoin('songs as individual_songs', 'ts_items.song_id', '=', 'individual_songs.id')
            ->whereHas('archive', function ($q) use ($channel) {
                $q->where('channel_id', $channel->channel_id)
                    ->where('is_display', 1);
            })
            ->whereNotNull('ts_items.text')
            ->where('ts_items.text', '!=', '')
            ->whereNotNull('ts_items.normalized_text')
            ->where('ts_items.is_display', 1);
        $this->applyIsNotSongFilter($countSameKeyQuery);
        $countSameKeyQuery->whereRaw("{$coalesce} = ?", [$sortKey])
            ->where('ts_items.id', '<', $itemId);
        $this->applyFilters($countSameKeyQuery, $search, $index, $publishedFrom, $publishedTo);
        $countSameKey = $countSameKeyQuery->count();

        return $countBefore + $countSameKey + 1;
    }

    /**
     * LEFT JOIN結果（mapping_status / is_manual列を持つ行）が確定済みマッピングかどうかを判定
     *
     * TimestampSongMapping::isConfirmed() と同じ条件だが、$item は
     * TsItem にマッピング列を追加した行であり TimestampSongMapping のインスタンスではないため
     * 直接そのメソッドは使えない。条件は TimestampSongMapping::confirmedJoinConditions() から取得し、
     * is_manual/status の条件を直接書かないようにする。
     */
    private function isConfirmedMappingRow($item): bool
    {
        $conditions = TimestampSongMapping::confirmedJoinConditions();

        return $item->mapping_status === $conditions['timestamp_song_mappings.status']
            && (bool) $item->is_manual === $conditions['timestamp_song_mappings.is_manual'];
    }

    /**
     * 「楽曲ではない」フィルタを適用（個別マッピングがある場合は常に表示）
     */
    private function applyIsNotSongFilter(Builder $query): void
    {
        $query->where(function ($q) {
            $q->whereNotNull('ts_items.song_id')
                ->orWhereNull('timestamp_song_mappings.id')
                ->orWhere('timestamp_song_mappings.is_not_song', false);
        });
    }

    /**
     * 個別マッピングまたはテキストマッピングから楽曲情報を組み立てる
     *
     * @param  Song|null  $individualSong  個別マッピングの楽曲
     * @param  object  $item  JOIN結果の行
     * @param  bool  $includeDuration  duration_msを含めるか
     */
    private function buildSongInfo(?Song $individualSong, $item, bool $includeDuration = false): ?array
    {
        if ($individualSong) {
            $info = [
                'title' => $individualSong->title,
                'artist' => $individualSong->artist,
                'spotify_track_id' => ValidationHelper::validateSpotifyTrackId($individualSong->spotify_track_id),
            ];
            if ($includeDuration) {
                $songDurationMs = null;
                if ($individualSong->spotify_data) {
                    $spotifyData = is_string($individualSong->spotify_data)
                        ? json_decode($individualSong->spotify_data, true)
                        : $individualSong->spotify_data;
                    $songDurationMs = $spotifyData['duration_ms'] ?? null;
                }
                $info['duration_ms'] = $songDurationMs;
            }

            return $info;
        }

        if ($item->mapping_id && $item->song_id && $this->isConfirmedMappingRow($item)) {
            $info = [
                'title' => $item->song_title,
                'artist' => $item->song_artist,
                'spotify_track_id' => ValidationHelper::validateSpotifyTrackId($item->spotify_track_id),
            ];
            if ($includeDuration) {
                $songDurationMs = null;
                if ($item->spotify_data) {
                    $spotifyData = is_string($item->spotify_data)
                        ? json_decode($item->spotify_data, true)
                        : $item->spotify_data;
                    $songDurationMs = $spotifyData['duration_ms'] ?? null;
                }
                $info['duration_ms'] = $songDurationMs;
            }

            return $info;
        }

        return null;
    }

    /**
     * 個別マッピングまたはテキストマッピングからmapping応答オブジェクトを組み立てる
     */
    private function buildMappingResponse(?Song $individualSong, $item, ?array $songInfo): ?array
    {
        if (! $item->mapping_id && ! $individualSong) {
            return null;
        }

        return [
            'song' => $songInfo,
            'is_not_song' => $individualSong ? false : ($item->mapping_id ? (bool) $item->is_not_song : false),
            'is_manual' => $individualSong ? false : ($item->mapping_id ? (bool) $item->is_manual : false),
        ];
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
