<?php

namespace App\Http\Controllers;

use App\Helpers\QueryHelper;
use App\Helpers\TextNormalizer;
use App\Helpers\ValidationHelper;
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
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SongController extends Controller
{
    protected SongSearchService $songSearchService;

    protected SongMappingService $songMappingService;

    protected SpotifyService $spotifyService;

    public function __construct(
        SongSearchService $songSearchService,
        SongMappingService $songMappingService,
        SpotifyService $spotifyService
    ) {
        $this->songSearchService = $songSearchService;
        $this->songMappingService = $songMappingService;
        $this->spotifyService = $spotifyService;
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
     */
    public function fetchTimestamps(FetchTimestampsRequest $request)
    {
        $validated = $request->validated();

        $perPage = $validated['per_page'] ?? 50;
        $search = $validated['search'] ?? '';
        $unlinkedOnly = ValidationHelper::parseBoolean($validated['unlinked_only'] ?? false);
        $currentPage = $validated['page'] ?? 1;

        $query = TsItem::with(['archive'])
            ->whereNotNull('text')
            ->where('text', '!=', '')
            // ts_items自体のis_displayが0のものを除外
            // （change_listの内容はRefreshArchiveServiceによりis_displayに反映済み）
            ->where('is_display', 1)
            // archiveのis_displayが0のものを除外
            // （change_listの内容はRefreshArchiveServiceによりis_displayに反映済み）
            ->whereHas('archive', function ($q) {
                $q->where('is_display', 1);
            });

        // 検索条件
        if ($search) {
            $escapedSearch = QueryHelper::escapeLikeString($search);
            $query->where('text', 'like', "%{$escapedSearch}%");
        }

        // 全件取得（ページネーション前）
        $allTimestamps = $query->get();

        // N+1クエリ問題を回避: 全タイムスタンプの正規化テキストを事前に取得
        $normalizedTexts = $allTimestamps->map(function ($item) {
            return TextNormalizer::normalize($item->text);
        })->unique()->values()->toArray();

        // 一度にすべてのマッピングを取得
        $mappings = TimestampSongMapping::whereIn('normalized_text', $normalizedTexts)
            ->with('song')
            ->get()
            ->keyBy('normalized_text');

        // 各タイムスタンプにマッピング情報を追加
        $timestampsWithMapping = $allTimestamps->map(function ($item) use ($mappings) {
            $normalizedText = TextNormalizer::normalize($item->text);
            $mapping = $mappings->get($normalizedText);

            // モデルを配列に変換して、追加のフィールドをマージ
            $data = $item->toArray();
            $data['normalized_text'] = $normalizedText;
            $data['mapping'] = $mapping ? $mapping->toArray() : null;
            $data['song'] = $mapping && $mapping->song ? $mapping->song->toArray() : null;
            $data['is_not_song'] = $mapping ? $mapping->is_not_song : false;

            return $data;
        });

        // 未連携フィルター（ソート前に適用）
        if ($unlinkedOnly) {
            $timestampsWithMapping = $timestampsWithMapping->filter(function ($item) {
                return ! $item['mapping'];
            })->values();
        }

        // 紐づけた楽曲を最後に表示するようにソート
        // 未紐づけ → 楽曲ではない → 紐づけ済み の順、それぞれtext昇順
        $sorted = $timestampsWithMapping->sort(function ($a, $b) {
            $aMapped = ! empty($a['mapping']);
            $bMapped = ! empty($b['mapping']);
            $aIsNotSong = $a['is_not_song'];
            $bIsNotSong = $b['is_not_song'];

            // 優先順位を決定（数値が小さいほど先に表示）
            // 0: 未紐づけ, 1: 楽曲ではない, 2: 紐づけ済み
            $aPriority = $aMapped ? ($aIsNotSong ? 1 : 2) : 0;
            $bPriority = $bMapped ? ($bIsNotSong ? 1 : 2) : 0;

            // 優先順位が異なる場合
            if ($aPriority !== $bPriority) {
                return $aPriority - $bPriority;
            }

            // 同じ優先順位の場合はtextで昇順ソート
            return strcmp($a['text'], $b['text']);
        })->values();

        // 手動でページネーション
        $total = $sorted->count();
        $lastPage = (int) ceil($total / $perPage);
        $offset = ($currentPage - 1) * $perPage;
        $items = $sorted->slice($offset, $perPage)->values();

        return response()->json([
            'data' => $items,
            'current_page' => $currentPage,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'total' => $total,
            'from' => $total > 0 ? $offset + 1 : null,
            'to' => $total > 0 ? min($offset + $perPage, $total) : null,
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
        $normalizedText = $validated['normalized_text'] ?? TextNormalizer::normalize($validated['text']);

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
}
