<?php

namespace App\Http\Controllers;

use App\Helpers\TextNormalizer;
use App\Models\Song;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Services\SpotifyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SongController extends Controller
{
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
    public function fetchTimestamps(Request $request)
    {
        $perPage = $request->input('per_page', 50);
        $search = $request->input('search', '');
        $unlinkedOnly = $request->input('unlinked_only', false);

        $query = TsItem::with(['archive']);

        // 検索条件
        if ($search) {
            $query->where('text', 'like', "%{$search}%");
        }

        $timestamps = $query->orderBy('text')->paginate($perPage);

        // 各タイムスタンプにマッピング情報を追加
        $timestamps->getCollection()->transform(function ($item) {
            $normalizedText = TextNormalizer::normalize($item->text);
            $mapping = TimestampSongMapping::where('normalized_text', $normalizedText)
                ->with('song')
                ->first();

            $item->normalized_text = $normalizedText;
            $item->mapping = $mapping;
            $item->song = $mapping ? $mapping->song : null;
            $item->is_not_song = $mapping ? $mapping->is_not_song : false;

            return $item;
        });

        // 未連携フィルター
        if ($unlinkedOnly) {
            $timestamps->setCollection(
                $timestamps->getCollection()->filter(function ($item) {
                    return !$item->mapping;
                })->values()
            );
        }

        return response()->json($timestamps);
    }

    /**
     * 楽曲マスタ一覧を取得
     */
    public function fetchSongs(Request $request)
    {
        $search = $request->input('search', '');

        $query = Song::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('artist', 'like', "%{$search}%");
            });
        }

        $songs = $query->orderBy('artist')
            ->orderBy('title')
            ->get();

        return response()->json($songs);
    }

    /**
     * 楽曲マスタを登録
     */
    public function storeSong(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string',
            'artist' => 'required|string',
            'spotify_track_id' => 'nullable|string|max:22',
            'spotify_data' => 'nullable|array',
        ]);

        // 文字数制限対応（255文字以内に切り詰め）
        $title = mb_substr($validated['title'], 0, 255);
        $artist = mb_substr($validated['artist'], 0, 255);

        $song = Song::create([
            'id' => Str::ulid(),
            'title' => $title,
            'artist' => $artist,
            'spotify_track_id' => $validated['spotify_track_id'] ?? null,
            'spotify_data' => $validated['spotify_data'] ?? null,
        ]);

        return response()->json($song, 201);
    }

    /**
     * タイムスタンプと楽曲を紐づける（マッピングを作成）
     */
    public function linkTimestamp(Request $request)
    {
        $validated = $request->validate([
            'normalized_text' => 'required|string',
            'song_id' => 'required|string|exists:songs,id',
        ]);

        DB::transaction(function () use ($validated) {
            TimestampSongMapping::updateOrCreate(
                ['normalized_text' => $validated['normalized_text']],
                [
                    'id' => Str::ulid(),
                    'song_id' => $validated['song_id'],
                    'is_not_song' => false,
                    'is_manual' => true,
                    'confidence' => 1.0,
                ]
            );
        });

        return response()->json(['message' => 'タイムスタンプと楽曲を紐づけました。']);
    }

    /**
     * タイムスタンプを「楽曲ではない」とマーク
     */
    public function markAsNotSong(Request $request)
    {
        $validated = $request->validate([
            'normalized_text' => 'required|string',
        ]);

        DB::transaction(function () use ($validated) {
            TimestampSongMapping::updateOrCreate(
                ['normalized_text' => $validated['normalized_text']],
                [
                    'id' => Str::ulid(),
                    'song_id' => null,
                    'is_not_song' => true,
                    'is_manual' => true,
                    'confidence' => 1.0,
                ]
            );
        });

        return response()->json(['message' => '楽曲ではないとマークしました。']);
    }

    /**
     * マッピングを解除
     */
    public function unlinkTimestamp(Request $request)
    {
        $validated = $request->validate([
            'normalized_text' => 'required|string',
        ]);

        TimestampSongMapping::where('normalized_text', $validated['normalized_text'])->delete();

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

        $threshold = $validated['threshold'] ?? 0.7;
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
            $clientId = config('services.spotify.client_id');
            $clientSecret = config('services.spotify.client_secret');

            if (!$clientId || !$clientSecret) {
                return response()->json([
                    'error' => 'Spotify API credentials are not configured.'
                ], 500);
            }

            $spotifyService = new SpotifyService();
            $spotifyService->authenticate($clientId, $clientSecret);

            $tracks = $spotifyService->searchTracks(
                $validated['query'],
                $validated['limit'] ?? 10
            );

            return response()->json($tracks);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Spotify API search failed: ' . $e->getMessage()
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
        TimestampSongMapping::where('song_id', $id)->delete();

        $song->delete();

        return response()->json(['message' => '楽曲マスタを削除しました。']);
    }
}
