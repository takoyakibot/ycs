<?php

namespace App\Http\Controllers;

use App\Helpers\QueryHelper;
use App\Helpers\TextNormalizer;
use App\Http\Requests\FetchTimestampsRequest;
use App\Http\Requests\LinkTimestampRequest;
use App\Http\Requests\MarkAsNotSongRequest;
use App\Http\Requests\NormalizedTextRequest;
use App\Http\Requests\StoreSongRequest;
use App\Models\Song;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Services\SongMappingService;
use App\Services\SongSearchService;
use App\Services\SpotifyService;
use App\Services\YouTubeApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SongController extends Controller
{
    protected SongSearchService $songSearchService;

    protected SongMappingService $songMappingService;

    protected SpotifyService $spotifyService;

    protected YouTubeApiService $youtubeApiService;

    public function __construct(
        SongSearchService $songSearchService,
        SongMappingService $songMappingService,
        SpotifyService $spotifyService,
        YouTubeApiService $youtubeApiService
    ) {
        $this->songSearchService = $songSearchService;
        $this->songMappingService = $songMappingService;
        $this->spotifyService = $spotifyService;
        $this->youtubeApiService = $youtubeApiService;
    }

    /**
     * タイムスタンプ正規化画面を表示
     */
    public function index()
    {
        return view('songs.index');
    }

    /**
     * 全タイムスタンプを取得（マッピング情報付き）
     * DBレベルでフィルタリング・ページネーションを実行
     */
    public function fetchTimestamps(FetchTimestampsRequest $request)
    {
        $validated = $request->validated();

        $perPage = $validated['per_page'] ?? 50;
        $search = $validated['search'] ?? '';
        $filter = $validated['filter'] ?? 'all';
        $currentPage = $validated['page'] ?? 1;

        // ベースクエリ: ts_itemsとtimestamp_song_mappingsをLEFT JOIN
        $query = TsItem::with(['archive'])
            ->leftJoin('timestamp_song_mappings', 'ts_items.normalized_text', '=', 'timestamp_song_mappings.normalized_text')
            ->select('ts_items.*', 'timestamp_song_mappings.id as mapping_id', 'timestamp_song_mappings.song_id', 'timestamp_song_mappings.is_not_song', 'timestamp_song_mappings.is_manual')
            ->whereNotNull('ts_items.text')
            ->where('ts_items.text', '!=', '')
            ->whereNotNull('ts_items.normalized_text') // マイグレーション後の未更新レコードを除外
            ->where('ts_items.is_display', 1)
            ->whereHas('archive', function ($q) {
                $q->where('is_display', 1);
            });

        // 検索条件
        if ($search) {
            $escapedSearch = QueryHelper::escapeLikeString($search);
            $query->where('ts_items.text', 'like', "%{$escapedSearch}%");
        }

        // フィルター条件
        switch ($filter) {
            case 'unlinked':
                // マッピングなし
                $query->whereNull('timestamp_song_mappings.id');
                break;
            case 'linked':
                // マッピングあり かつ is_not_song=false
                $query->whereNotNull('timestamp_song_mappings.id')
                    ->where('timestamp_song_mappings.is_not_song', false);
                break;
            case 'not_song':
                // マッピングあり かつ is_not_song=true
                $query->whereNotNull('timestamp_song_mappings.id')
                    ->where('timestamp_song_mappings.is_not_song', true);
                break;
            case 'auto_linked':
                // 自動紐付け: マッピングあり かつ is_manual=false かつ is_not_song=false
                $query->whereNotNull('timestamp_song_mappings.id')
                    ->where('timestamp_song_mappings.is_manual', false)
                    ->where('timestamp_song_mappings.is_not_song', false);
                break;
                // 'all' は条件なし
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

        // 各タイムスタンプにマッピング情報を追加
        $items = $paginated->getCollection()->map(function ($item) use ($mappings) {
            $mapping = $mappings->get($item->normalized_text);

            $data = $item->toArray();
            $data['normalized_text'] = $item->normalized_text;
            $data['mapping'] = $mapping ? $mapping->toArray() : null;
            $data['song'] = $mapping && $mapping->song ? $mapping->song->toArray() : null;
            $data['is_not_song'] = $mapping ? $mapping->is_not_song : false;
            // is_manual は mapping から取得（JOINのカラムは後で削除）
            $isManual = $mapping ? $mapping->is_manual : null;

            // JOINで追加されたカラムを削除
            unset($data['mapping_id'], $data['song_id'], $data['is_manual']);

            // mapping から取得した is_manual を設定
            $data['is_manual'] = $isManual;

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
        ]);

        $search = $validated['search'] ?? '';

        $query = Song::query();

        if ($search) {
            $escapedSearch = QueryHelper::escapeLikeString($search);
            $query->where(function ($q) use ($escapedSearch) {
                $q->where('title', 'like', "%{$escapedSearch}%")
                    ->orWhere('artist', 'like', "%{$escapedSearch}%");
            });
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
                $song = Song::create([
                    'id' => Str::ulid(),
                    'title' => $title,
                    'artist' => $artist,
                    'spotify_track_id' => $validated['spotify_track_id'] ?? null,
                    'spotify_data' => $validated['spotify_data'] ?? null,
                ]);

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
            $song = Song::create([
                'id' => Str::ulid(),
                'title' => $title,
                'artist' => $artist,
                'spotify_track_id' => $validated['spotify_track_id'] ?? null,
                'spotify_data' => $validated['spotify_data'] ?? null,
            ]);

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

        // この楽曲に紐づいているマッピングを削除
        $this->songMappingService->deleteMappingsBySongId($id);

        $song->delete();

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
            'youtube_url' => 'nullable|url|max:255',
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
     * YouTube URLから動画の長さを取得
     */
    public function fetchYoutubeDuration(Request $request)
    {
        $validated = $request->validate([
            'youtube_url' => 'required|string|max:255',
        ]);

        $videoId = $this->youtubeApiService->extractVideoId($validated['youtube_url']);

        if (! $videoId) {
            return response()->json([
                'error' => '有効なYouTube URLではありません。',
            ], 422);
        }

        try {
            $durationMs = $this->youtubeApiService->getVideoDuration($videoId);

            if ($durationMs === null) {
                return response()->json([
                    'error' => '動画情報を取得できませんでした。動画が存在しないか、非公開の可能性があります。',
                ], 404);
            }

            return response()->json([
                'duration_ms' => $durationMs,
                'video_id' => $videoId,
            ]);
        } catch (\Exception $e) {
            $message = app()->environment('production')
                ? '動画情報の取得に失敗しました。'
                : $e->getMessage();

            return response()->json([
                'error' => $message,
            ], 500);
        }
    }
}
