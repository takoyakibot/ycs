<?php

namespace App\Http\Controllers;

use App\Models\Song;
use App\Models\TsItem;
use App\Services\SpotifyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimestampNormalizationController extends Controller
{
    private $spotifyService;

    public function __construct(SpotifyService $spotifyService)
    {
        $this->spotifyService = $spotifyService;
    }

    /**
     * タイムスタンプ正規化画面を表示
     */
    public function index()
    {
        return view('manage.timestamp-normalization');
    }

    /**
     * タイムスタンプリストを取得（ページネーション付き）
     */
    public function getTimestamps(Request $request): JsonResponse
    {
        $perPage = $request->get('per_page', 50);
        $search = $request->get('search', '');

        $query = TsItem::with(['song', 'archive'])
            ->orderBy('ts_num', 'desc');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('ts_text', 'like', "%{$search}%")
                    ->orWhere('text', 'like', "%{$search}%");
            });
        }

        $timestamps = $query->paginate($perPage);

        return response()->json([
            'timestamps' => $timestamps->items(),
            'pagination' => [
                'current_page' => $timestamps->currentPage(),
                'last_page' => $timestamps->lastPage(),
                'per_page' => $timestamps->perPage(),
                'total' => $timestamps->total(),
            ],
        ]);
    }

    /**
     * Spotify検索
     */
    public function searchSpotify(Request $request): JsonResponse
    {
        $query = $request->input('query');
        if (! $query) {
            return response()->json(['tracks' => []]);
        }

        try {
            $tracks = $this->spotifyService->searchTracks($query);

            return response()->json(['tracks' => $tracks]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Spotify検索でエラーが発生しました'], 500);
        }
    }

    /**
     * 楽曲マスタを作成
     */
    public function createSong(Request $request): JsonResponse
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'artist' => 'required|string|max:255',
            'spotify_id' => 'nullable|string',
            'spotify_uri' => 'nullable|string',
            'spotify_preview_url' => 'nullable|url',
            'spotify_data' => 'nullable|array',
        ]);

        try {
            $song = Song::create($request->all());

            return response()->json(['song' => $song]);
        } catch (\Exception $e) {
            return response()->json(['error' => '楽曲マスタの作成に失敗しました'], 500);
        }
    }

    /**
     * 楽曲マスタ一覧を取得
     */
    public function getSongs(Request $request): JsonResponse
    {
        $search = $request->get('search', '');
        $perPage = $request->get('per_page', 20);

        $query = Song::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('artist', 'like', "%{$search}%");
            });
        }

        $songs = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'songs' => $songs->items(),
            'pagination' => [
                'current_page' => $songs->currentPage(),
                'last_page' => $songs->lastPage(),
                'per_page' => $songs->perPage(),
                'total' => $songs->total(),
            ],
        ]);
    }

    /**
     * タイムスタンプと楽曲を紐づけ
     */
    public function linkTimestamp(Request $request): JsonResponse
    {
        $request->validate([
            'timestamp_id' => 'required|string',
            'song_id' => 'nullable|exists:songs,id',
            'is_not_song' => 'boolean',
        ]);

        try {
            $tsItem = TsItem::findOrFail($request->timestamp_id);

            if ($request->is_not_song) {
                $tsItem->update([
                    'song_id' => null,
                    'is_not_song' => true,
                ]);
            } else {
                $tsItem->update([
                    'song_id' => $request->song_id,
                    'is_not_song' => false,
                ]);
            }

            return response()->json([
                'message' => 'タイムスタンプの紐づけが完了しました',
                'timestamp' => $tsItem->load('song'),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'タイムスタンプの紐づけに失敗しました'], 500);
        }
    }

    /**
     * Spotifyから楽曲データを取得して楽曲マスタを作成
     */
    public function createSongFromSpotify(Request $request): JsonResponse
    {
        $request->validate([
            'spotify_id' => 'required|string',
        ]);

        try {
            $trackData = $this->spotifyService->getTrack($request->spotify_id);

            if (! $trackData) {
                return response()->json(['error' => 'Spotifyから楽曲データを取得できませんでした'], 404);
            }

            // 既に同じSpotify IDで登録されていないかチェック
            $existingSong = Song::where('spotify_id', $request->spotify_id)->first();
            if ($existingSong) {
                return response()->json(['song' => $existingSong]);
            }

            $song = Song::create([
                'title' => $trackData['name'],
                'artist' => $trackData['artist'],
                'spotify_id' => $trackData['id'],
                'spotify_uri' => $trackData['uri'],
                'spotify_preview_url' => $trackData['preview_url'],
                'spotify_data' => $trackData['raw_data'],
            ]);

            return response()->json(['song' => $song]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Spotifyからの楽曲作成に失敗しました'], 500);
        }
    }
}
