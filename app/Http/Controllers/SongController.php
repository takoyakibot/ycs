<?php

namespace App\Http\Controllers;

use App\Helpers\QueryHelper;
use App\Helpers\TextNormalizer;
use App\Http\Requests\FetchTimestampsRequest;
use App\Http\Requests\LinkTimestampRequest;
use App\Http\Requests\MarkAsNotSongRequest;
use App\Http\Requests\MatchCandidatesRequest;
use App\Http\Requests\NormalizedTextRequest;
use App\Http\Requests\StoreSongRequest;
use App\Models\NormalizationLog;
use App\Models\Song;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Services\SongMappingService;
use App\Services\SongMatchingService;
use App\Services\SongMergeService;
use App\Services\SongSearchService;
use App\Services\SpotifyService;
use App\Services\VideoUrlService;
use App\Services\YouTubeApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SongController extends Controller
{
    protected SongSearchService $songSearchService;

    protected SongMappingService $songMappingService;

    protected SongMergeService $songMergeService;

    protected SpotifyService $spotifyService;

    protected YouTubeApiService $youtubeApiService;

    protected VideoUrlService $videoUrlService;

    protected SongMatchingService $songMatchingService;

    public function __construct(
        SongSearchService $songSearchService,
        SongMappingService $songMappingService,
        SongMergeService $songMergeService,
        SpotifyService $spotifyService,
        YouTubeApiService $youtubeApiService,
        VideoUrlService $videoUrlService,
        SongMatchingService $songMatchingService
    ) {
        $this->songSearchService = $songSearchService;
        $this->songMappingService = $songMappingService;
        $this->songMergeService = $songMergeService;
        $this->spotifyService = $spotifyService;
        $this->youtubeApiService = $youtubeApiService;
        $this->videoUrlService = $videoUrlService;
        $this->songMatchingService = $songMatchingService;
    }

    /**
     * タイムスタンプ正規化画面を表示
     */
    public function index()
    {
        return view('songs.index', [
            'spotifyEnabled' => $this->spotifyService->isEnabled(),
        ]);
    }

    /**
     * 楽曲名寄せ画面を表示
     */
    public function duplicates()
    {
        return view('songs.duplicates');
    }

    /**
     * 重複楽曲グループを取得
     */
    public function findDuplicates(Request $request)
    {
        $search = (string) $request->query('search', '');
        $groups = $this->songMergeService->findDuplicates($search);

        return response()->json($groups);
    }

    /**
     * 名寄せ用の楽曲検索（部分一致）
     */
    public function searchSongsForMerge(Request $request)
    {
        $search = (string) $request->query('search', '');
        $songs = $this->songMergeService->searchSongs($search);

        return response()->json($songs);
    }

    /**
     * 楽曲をマージする
     */
    public function mergeSongs(Request $request)
    {
        $validated = $request->validate([
            'source_song_id' => 'required|string|exists:songs,id',
            'target_song_id' => 'required|string|exists:songs,id|different:source_song_id',
        ]);

        $result = $this->songMergeService->merge(
            $validated['source_song_id'],
            $validated['target_song_id']
        );

        return response()->json([
            'message' => sprintf(
                '楽曲をマージしました（マッピング %d件、個別紐付け %d件を移行）',
                $result['affected_mappings'],
                $result['affected_ts_items']
            ),
            'affected_mappings' => $result['affected_mappings'],
            'affected_ts_items' => $result['affected_ts_items'],
        ]);
    }

    /**
     * 全タイムスタンプを取得（マッピング情報付き）
     * DBレベルでフィルタリング・ページネーションを実行
     * Channel Adminの場合は自チャンネルのタイムスタンプのみ表示
     */
    public function fetchTimestamps(FetchTimestampsRequest $request)
    {
        $validated = $request->validated();
        $user = Auth::user();

        $perPage = $validated['per_page'] ?? 50;
        $search = $validated['search'] ?? '';
        $filter = $validated['filter'] ?? 'all';
        $currentPage = $validated['page'] ?? 1;
        $songId = $validated['song_id'] ?? null;

        // ベースクエリ: ts_itemsとtimestamp_song_mappingsをLEFT JOIN
        $query = TsItem::with(['archive'])
            ->leftJoin('timestamp_song_mappings', 'ts_items.normalized_text', '=', 'timestamp_song_mappings.normalized_text')
            ->select('ts_items.*', 'timestamp_song_mappings.id as mapping_id', 'timestamp_song_mappings.song_id', 'timestamp_song_mappings.is_not_song', 'timestamp_song_mappings.is_manual', 'timestamp_song_mappings.status as mapping_status')
            ->whereNotNull('ts_items.text')
            ->where('ts_items.text', '!=', '')
            ->whereNotNull('ts_items.normalized_text') // マイグレーション後の未更新レコードを除外
            ->where('ts_items.is_display', 1)
            ->whereHas('archive', function ($q) {
                $q->where('is_display', 1);
            });

        // Channel Admin（非Super Admin）の場合は自チャンネルのみ表示
        if (! $user->isSuperAdmin()) {
            $userChannelIds = $user->channels()->pluck('channel_id')->toArray();
            $query->whereHas('archive', function ($q) use ($userChannelIds) {
                $q->whereIn('channel_id', $userChannelIds);
            });
        }

        // 検索条件（スペース区切りでAND検索）
        if ($search) {
            $keywords = QueryHelper::splitSearchKeywords($search);
            foreach ($keywords as $keyword) {
                $escaped = QueryHelper::escapeLikeString($keyword);
                $query->where('ts_items.text', 'like', "%{$escaped}%");
            }
        }

        // フィルター条件
        // 個別マッピング（ts_items.song_id）も考慮
        switch ($filter) {
            case 'unlinked':
                // マッピングなし かつ 個別マッピングもなし
                $query->whereNull('timestamp_song_mappings.id')
                    ->whereNull('ts_items.song_id');
                break;
            case 'linked':
                // マッピングあり（通常または個別）かつ is_not_song=false かつ status=linked
                $query->where(function ($q) {
                    $q->where(function ($q2) {
                        // 通常マッピング
                        $q2->whereNotNull('timestamp_song_mappings.id')
                            ->where('timestamp_song_mappings.is_not_song', false)
                            ->where('timestamp_song_mappings.status', TimestampSongMapping::STATUS_LINKED);
                    })->orWhereNotNull('ts_items.song_id'); // 個別マッピング
                });
                break;
            case 'not_song':
                // マッピングあり かつ is_not_song=true（個別マッピングは含まない）
                $query->whereNotNull('timestamp_song_mappings.id')
                    ->where('timestamp_song_mappings.is_not_song', true);
                break;
            case 'auto_linked':
                // 自動紐付け: マッピングあり かつ is_manual=false かつ is_not_song=false かつ status=linked かつ 個別マッピングなし
                $query->whereNotNull('timestamp_song_mappings.id')
                    ->where('timestamp_song_mappings.is_manual', false)
                    ->where('timestamp_song_mappings.is_not_song', false)
                    ->where('timestamp_song_mappings.status', TimestampSongMapping::STATUS_LINKED)
                    ->whereNull('ts_items.song_id');
                break;
            case 'pending':
                // 保留: マッピングあり かつ status=pending
                $query->whereNotNull('timestamp_song_mappings.id')
                    ->where('timestamp_song_mappings.status', TimestampSongMapping::STATUS_PENDING);
                break;
            case 'active':
                // 有効: 非楽曲(is_not_song)と保留(pending)を除外
                $query->where(function ($q) {
                    $q->whereNull('timestamp_song_mappings.id')
                        ->orWhere(function ($q2) {
                            $q2->where('timestamp_song_mappings.is_not_song', false)
                                ->where('timestamp_song_mappings.status', '!=', TimestampSongMapping::STATUS_PENDING);
                        });
                });
                break;
                // 'all' は条件なし
        }

        // 楽曲IDによる絞り込み（既存フィルターと併用可能）
        if ($songId) {
            $query->where('timestamp_song_mappings.song_id', $songId);
        }

        // text昇順でソート
        $query->orderBy('ts_items.text', 'asc');

        // DBページネーション
        $paginated = $query->paginate($perPage, ['*'], 'page', $currentPage);

        // ページネーション結果のタイムスタンプに対してマッピング・楽曲情報を取得
        $normalizedTexts = $paginated->getCollection()->pluck('normalized_text')->unique()->values()->toArray();

        $mappings = TimestampSongMapping::whereIn('normalized_text', $normalizedTexts)
            ->with('song')
            ->get()
            ->keyBy('normalized_text');

        // 個別マッピングがあるタイムスタンプの楽曲情報を取得
        $tsItemIds = $paginated->getCollection()->pluck('id')->toArray();
        $individualSongs = TsItem::whereIn('id', $tsItemIds)
            ->whereNotNull('song_id')
            ->with('song')
            ->get()
            ->keyBy('id');

        // 各タイムスタンプにマッピング情報を追加
        $items = $paginated->getCollection()->map(function ($item) use ($mappings, $individualSongs) {
            $mapping = $mappings->get($item->normalized_text);
            $individualItem = $individualSongs->get($item->id);

            $data = $item->toArray();
            $data['normalized_text'] = $item->normalized_text;
            $data['mapping'] = $mapping ? $mapping->toArray() : null;

            // 個別マッピング（ts_items.song_id）があればそれを優先
            if ($individualItem && $individualItem->song) {
                $data['song'] = $individualItem->song->toArray();
                $data['is_individual_mapping'] = true;
                $data['individual_song_id'] = $individualItem->song_id;
            } else {
                $data['song'] = $mapping && $mapping->song ? $mapping->song->toArray() : null;
                $data['is_individual_mapping'] = false;
                $data['individual_song_id'] = null;
            }

            $data['is_not_song'] = $mapping ? $mapping->is_not_song : false;
            // is_manual, status は mapping から取得（JOINのカラムは後で削除）
            $isManual = $mapping ? $mapping->is_manual : null;
            $status = $mapping ? $mapping->status : null;

            // JOINで追加されたカラムを削除
            unset($data['mapping_id'], $data['song_id'], $data['is_manual'], $data['mapping_status']);

            // mapping から取得した is_manual, status を設定
            $data['is_manual'] = $isManual;
            $data['status'] = $status;

            return $data;
        });

        return response()->json([
            'data' => $items->values(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'per_page' => $paginated->perPage(),
            'total' => $paginated->total(),
            'from' => $paginated->firstItem(),
            'to' => $paginated->lastItem(),
        ]);
    }

    /**
     * 楽曲マスタ一覧を取得
     */
    public function fetchSongs(Request $request)
    {
        // バリデーション
        $validated = $request->validate([
            'search' => 'nullable|string|max:255',
            'review_status' => 'nullable|string|in:safe,needs_review',
        ]);

        $search = $validated['search'] ?? '';
        $reviewStatus = $validated['review_status'] ?? null;

        $query = Song::query();

        // review_statusフィルタ
        if ($reviewStatus !== null) {
            $query->where('review_status', $reviewStatus);
        }

        // 検索条件（スペース区切りでAND検索）
        // 各キーワードがtitleまたはartistのいずれかに含まれる
        if ($search) {
            $keywords = QueryHelper::splitSearchKeywords($search);
            foreach ($keywords as $keyword) {
                $escaped = QueryHelper::escapeLikeString($keyword);
                $query->where(function ($q) use ($escaped) {
                    $q->where('title', 'like', "%{$escaped}%")
                        ->orWhere('artist', 'like', "%{$escaped}%");
                });
            }
        }

        $total = $query->count();
        $songs = $query->orderBy('artist')
            ->orderBy('title')
            ->get();

        return response()->json([
            'data' => $songs,
            'total' => $total,
        ]);
    }

    /**
     * 楽曲マスタを登録
     */
    public function storeSong(StoreSongRequest $request)
    {
        $validated = $request->validated();

        $title = trim($validated['title']);
        $artist = trim($validated['artist']);

        // 既存曲を使用する場合
        if (! empty($validated['use_existing_id'])) {
            $existingSong = Song::findOrFail($validated['use_existing_id']);

            return response()->json([
                'status' => 'existing_used',
                'song' => $existingSong,
                'message' => '既存の楽曲マスタを使用します。',
            ], 200);
        }

        // 強制新規登録フラグがある場合はチェックをスキップ
        if (! empty($validated['force_create'])) {
            try {
                $userId = Auth::id();
                $song = Song::create([
                    'id' => Str::ulid(),
                    'title' => $title,
                    'artist' => $artist,
                    'spotify_track_id' => $validated['spotify_track_id'] ?? null,
                    'spotify_data' => $validated['spotify_data'] ?? null,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);

                // 操作ログを記録
                if ($userId) {
                    NormalizationLog::log(
                        $userId,
                        NormalizationLog::ACTION_CREATE_SONG,
                        NormalizationLog::TARGET_SONG,
                        $song->id,
                        ['title' => $title, 'artist' => $artist]
                    );
                }

                return response()->json([
                    'status' => 'created',
                    'song' => $song,
                    'message' => '新規の楽曲マスタを作成しました。',
                ], 201);
            } catch (\Illuminate\Database\QueryException $e) {
                // ユニーク制約違反の場合は既存レコードを返す
                if ($e->getCode() == 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
                    $existingSong = Song::where('title', $title)
                        ->where('artist', $artist)
                        ->orWhere('spotify_track_id', $validated['spotify_track_id'])
                        ->first();

                    if ($existingSong) {
                        return response()->json([
                            'status' => 'exact_match',
                            'song' => $existingSong,
                            'message' => '既に登録されている楽曲マスタが見つかりました。',
                        ], 200);
                    }
                }
                throw $e;
            }
        }

        // 完全一致チェック
        $existingSong = null;

        // Spotify Track IDが指定されている場合はそれで完全一致チェック
        if (! empty($validated['spotify_track_id'])) {
            $existingSong = Song::where('spotify_track_id', $validated['spotify_track_id'])->first();
            if ($existingSong) {
                return response()->json([
                    'status' => 'exact_match',
                    'song' => $existingSong,
                    'message' => '既に登録されている楽曲マスタが見つかりました。',
                ], 200);
            }
        }

        // Title + Artist の正規化後の完全一致チェック
        $normalizedTitle = TextNormalizer::normalize($title);
        $normalizedArtist = TextNormalizer::normalize($artist);

        $exactMatch = $this->songSearchService->findExactMatch($normalizedTitle, $normalizedArtist);
        if ($exactMatch) {
            return response()->json([
                'status' => 'exact_match',
                'song' => $exactMatch,
                'message' => '既に登録されている楽曲マスタが見つかりました。',
            ], 200);
        }

        // 類似度チェック
        $threshold = config('songs.similarity_threshold', 0.75);
        $similarSongs = $this->songSearchService->findSimilarSongs($normalizedTitle, $normalizedArtist, $threshold);

        if (count($similarSongs) > 0) {
            return response()->json([
                'status' => 'similar_found',
                'similar_songs' => $similarSongs,
                'input' => [
                    'title' => $title,
                    'artist' => $artist,
                    'spotify_track_id' => $validated['spotify_track_id'] ?? null,
                    'spotify_data' => $validated['spotify_data'] ?? null,
                ],
                'message' => '類似する楽曲マスタが見つかりました。既存のマスタを使用するか、新規登録するか選択してください。',
            ], 200);
        }

        // 新規登録
        try {
            $userId = Auth::id();
            $song = Song::create([
                'id' => Str::ulid(),
                'title' => $title,
                'artist' => $artist,
                'spotify_track_id' => $validated['spotify_track_id'] ?? null,
                'spotify_data' => $validated['spotify_data'] ?? null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            // 操作ログを記録
            if ($userId) {
                NormalizationLog::log(
                    $userId,
                    NormalizationLog::ACTION_CREATE_SONG,
                    NormalizationLog::TARGET_SONG,
                    $song->id,
                    ['title' => $title, 'artist' => $artist]
                );
            }

            return response()->json([
                'status' => 'created',
                'song' => $song,
                'message' => '新規の楽曲マスタを作成しました。',
            ], 201);
        } catch (\Illuminate\Database\QueryException $e) {
            // ユニーク制約違反の場合は既存レコードを返す
            if ($e->getCode() == 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
                $existingSong = Song::where('title', $title)
                    ->where('artist', $artist)
                    ->orWhere('spotify_track_id', $validated['spotify_track_id'])
                    ->first();

                if ($existingSong) {
                    return response()->json([
                        'status' => 'exact_match',
                        'song' => $existingSong,
                        'message' => '既に登録されている楽曲マスタが見つかりました。',
                    ], 200);
                }
            }
            throw $e;
        }
    }

    /**
     * タイムスタンプと楽曲を紐づける（マッピングを作成）
     */
    public function linkTimestamp(LinkTimestampRequest $request)
    {
        $validated = $request->validated();

        $this->songMappingService->linkTimestamp($validated['normalized_text'], $validated['song_id']);

        return response()->json(['message' => 'タイムスタンプと楽曲を紐づけました。']);
    }

    /**
     * タイムスタンプを「楽曲ではない」とマーク
     */
    public function markAsNotSong(MarkAsNotSongRequest $request)
    {
        $validated = $request->validated();

        // textが渡された場合は正規化する
        // 正規化結果が空文字列になる場合（例: "-"のみ）は元のテキストを使用
        if (isset($validated['normalized_text']) && $validated['normalized_text'] !== '') {
            $normalizedText = $validated['normalized_text'];
        } else {
            $originalText = $validated['text'] ?? '';
            $normalizedText = TextNormalizer::normalize($originalText);
            if ($normalizedText === '') {
                $normalizedText = mb_strtolower(trim($originalText), 'UTF-8');
            }
        }

        if ($normalizedText === '') {
            return response()->json(['message' => 'テキストが空です。'], 422);
        }

        $this->songMappingService->markAsNotSong($normalizedText);

        return response()->json(['message' => '楽曲ではないとマークしました。']);
    }

    /**
     * 「楽曲ではない」フラグを解除
     */
    public function unmarkAsNotSong(NormalizedTextRequest $request)
    {
        $validated = $request->validated();

        $this->songMappingService->unmarkAsNotSong($validated['normalized_text']);

        return response()->json(['message' => '「楽曲ではない」マークを解除しました。']);
    }

    /**
     * マッピングを解除
     */
    public function unlinkTimestamp(NormalizedTextRequest $request)
    {
        $validated = $request->validated();

        $this->songMappingService->unlinkTimestamp($validated['normalized_text']);

        return response()->json(['message' => 'マッピングを解除しました。']);
    }

    /**
     * 自動紐付けを確定（手動紐付けに変更）
     */
    public function confirmAutoLink(NormalizedTextRequest $request)
    {
        $validated = $request->validated();

        $confirmed = $this->songMappingService->confirmAutoLink($validated['normalized_text']);

        if (! $confirmed) {
            return response()->json(['message' => '確定対象のマッピングが見つかりません。'], 404);
        }

        return response()->json(['message' => '自動紐付けを確定しました。']);
    }

    /**
     * タイムスタンプを「保留」状態にする
     * 自動紐付けを解除し、再び自動紐付けの対象にならないようにする
     */
    public function markAsPending(NormalizedTextRequest $request)
    {
        $validated = $request->validated();

        $this->songMappingService->markAsPending($validated['normalized_text']);

        return response()->json(['message' => '保留状態にしました。']);
    }

    /**
     * 未紐付けテキストに対する楽曲マスタの照合候補を取得
     *
     * 正規化画面で1件ずつ検索する手間を省くため、表示中のテキストに対して
     * 「マスタのタイトルが含まれているか」で照合した候補を返す。
     * 自動紐付けの閾値には達しないが可能性のある候補も含めて提示する。
     */
    public function matchCandidates(MatchCandidatesRequest $request)
    {
        $validated = $request->validated();

        $candidateThreshold = (float) config('songs.matching.candidate_threshold', 0.5);
        $autoLinkThreshold = (float) config('songs.matching.auto_link_threshold', 0.85);

        $candidates = [];

        // 同一テキストが複数渡された場合に無駄な照合をしないよう重複を除く
        foreach (array_unique($validated['normalized_texts']) as $normalizedText) {
            $matches = array_values(array_filter(
                $this->songMatchingService->findCandidates($normalizedText),
                fn ($candidate) => $candidate['confidence'] >= $candidateThreshold
            ));

            if (! empty($matches)) {
                $candidates[$normalizedText] = $matches;
            }
        }

        return response()->json([
            'candidates' => $candidates,
            'auto_link_threshold' => $autoLinkThreshold,
            'candidate_threshold' => $candidateThreshold,
        ]);
    }

    /**
     * あいまい検索で類似するマッピングを検索
     */
    public function fuzzySearch(Request $request)
    {
        $validated = $request->validate([
            'text' => 'required|string',
            'threshold' => 'numeric|min:0|max:1',
        ]);

        $threshold = $validated['threshold'] ?? config('songs.fuzzy_search_threshold', 0.7);
        $mapping = TimestampSongMapping::fuzzySearch($validated['text'], $threshold);

        if ($mapping) {
            $mapping->load('song');

            return response()->json([
                'found' => true,
                'mapping' => $mapping,
                'confidence' => $mapping->confidence,
            ]);
        }

        return response()->json(['found' => false]);
    }

    /**
     * Spotify APIで楽曲を検索
     */
    public function searchSpotify(Request $request)
    {
        if (! $this->spotifyService->isEnabled()) {
            return response()->json([
                'error' => 'Spotify API連携は現在無効になっています。',
            ], 503);
        }

        $validated = $request->validate([
            'query' => 'required|string',
            'limit' => 'integer|min:1|max:50',
        ]);

        try {
            $tracks = $this->spotifyService->searchWithAuth(
                $validated['query'],
                $validated['limit'] ?? 10
            );

            return response()->json($tracks);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 楽曲マスタを削除
     */
    public function deleteSong(Request $request, $id)
    {
        $song = Song::findOrFail($id);
        $userId = Auth::id();

        DB::transaction(function () use ($song, $id, $userId) {
            // この楽曲に紐づいているマッピングを削除（ログ記録含む）
            $this->songMappingService->deleteMappingsBySongId($id, $userId);

            // 個別紐付け（ts_items.song_id）をクリア
            TsItem::where('song_id', $id)->update(['song_id' => null]);

            // 操作ログを記録
            if ($userId) {
                NormalizationLog::log(
                    $userId,
                    NormalizationLog::ACTION_DELETE_SONG,
                    NormalizationLog::TARGET_SONG,
                    $song->id,
                    ['title' => $song->title, 'artist' => $song->artist]
                );
            }

            $song->delete();
        });

        return response()->json(['message' => '楽曲マスタを削除しました。']);
    }

    /**
     * 楽曲マスタを更新
     */
    public function updateSong(Request $request, $id)
    {
        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'artist' => 'sometimes|required|string|max:255',
            'video_url' => 'nullable|url|max:255',
            'duration_ms' => 'nullable|integer|min:0|max:86400000', // 最大24時間
        ]);

        $song = Song::findOrFail($id);
        $song->update($validated);

        return response()->json([
            'message' => '楽曲マスタを更新しました。',
            'song' => $song,
        ]);
    }

    /**
     * 動画URLから動画の長さを取得
     * YouTube および ニコニコ動画に対応
     */
    public function fetchVideoDuration(Request $request)
    {
        $validated = $request->validate([
            'video_url' => 'required|string|max:255',
        ]);

        $result = $this->videoUrlService->getVideoDuration($validated['video_url']);

        if ($result['error'] !== null && $result['duration_ms'] === null) {
            return response()->json([
                'error' => $result['error'],
                'platform' => $result['platform'],
                'video_id' => $result['video_id'],
            ], 422);
        }

        return response()->json([
            'duration_ms' => $result['duration_ms'],
            'video_id' => $result['video_id'],
            'platform' => $result['platform'],
            'error' => $result['error'],
        ]);
    }

    /**
     * 特定のタイムスタンプに個別で楽曲を紐づける
     */
    public function linkTsItemToSong(Request $request)
    {
        $validated = $request->validate([
            'ts_item_id' => 'required|string|max:26',
            'song_id' => 'required|string|max:26',
        ]);

        $user = Auth::user();
        $tsItem = TsItem::with('archive')->find($validated['ts_item_id']);

        if (! $tsItem) {
            return response()->json(['message' => 'タイムスタンプが見つかりません。'], 404);
        }

        // Channel Admin権限チェック
        if (! $user->isSuperAdmin()) {
            $userChannelIds = $user->channels()->pluck('channel_id')->toArray();
            if (! in_array($tsItem->archive?->channel_id, $userChannelIds)) {
                return response()->json(['message' => '権限がありません。'], 403);
            }
        }

        // 楽曲の存在チェック
        $song = Song::find($validated['song_id']);
        if (! $song) {
            return response()->json(['message' => '指定された楽曲が見つかりません。'], 404);
        }

        $this->songMappingService->linkTsItemToSong($validated['ts_item_id'], $validated['song_id']);

        return response()->json(['message' => 'タイムスタンプに個別で楽曲を紐づけました。']);
    }

    /**
     * 特定のタイムスタンプの個別マッピングを解除
     */
    public function unlinkTsItem(Request $request)
    {
        $validated = $request->validate([
            'ts_item_id' => 'required|string|max:26',
        ]);

        $user = Auth::user();
        $tsItem = TsItem::with('archive')->find($validated['ts_item_id']);

        if (! $tsItem) {
            return response()->json(['message' => 'タイムスタンプが見つかりません。'], 404);
        }

        // Channel Admin権限チェック
        if (! $user->isSuperAdmin()) {
            $userChannelIds = $user->channels()->pluck('channel_id')->toArray();
            if (! in_array($tsItem->archive?->channel_id, $userChannelIds)) {
                return response()->json(['message' => '権限がありません。'], 403);
            }
        }

        $this->songMappingService->unlinkTsItem($validated['ts_item_id']);

        return response()->json(['message' => 'タイムスタンプの個別マッピングを解除しました。']);
    }

    /**
     * 同じnormalized_textを持つタイムスタンプの情報を取得
     * Channel Adminの場合は自チャンネルのタイムスタンプのみ返す
     */
    public function getTsItemsByNormalizedText(Request $request)
    {
        $validated = $request->validate([
            'normalized_text' => 'required|string',
        ]);

        $user = Auth::user();
        $items = $this->songMappingService->getTsItemsByNormalizedText($validated['normalized_text']);

        // Channel Adminの場合は自チャンネルのみフィルタリング
        if (! $user->isSuperAdmin()) {
            $userChannelIds = $user->channels()->pluck('channel_id')->toArray();
            $items = array_filter($items, function ($item) use ($userChannelIds) {
                $channelId = $item['archive_channel_id'] ?? null;

                return in_array($channelId, $userChannelIds);
            });
            $items = array_values($items); // インデックスを振り直す
        }

        $count = count($items);

        // 現在のマッピング情報も取得
        $mapping = TimestampSongMapping::where('normalized_text', $validated['normalized_text'])
            ->with('song')
            ->first();

        return response()->json([
            'count' => $count,
            'items' => $items,
            'current_mapping' => $mapping ? [
                'song_id' => $mapping->song_id,
                'song' => $mapping->song,
                'is_not_song' => $mapping->is_not_song,
                'is_manual' => $mapping->is_manual,
            ] : null,
        ]);
    }
}
