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
        // バリデーション
        $validated = $request->validate([
            'per_page' => 'integer|min:1|max:100',
            'page' => 'integer|min:1',
            'search' => 'nullable|string|max:255',
            'unlinked_only' => 'nullable|in:true,false,1,0',
        ]);

        $perPage = $validated['per_page'] ?? 50;
        $search = $validated['search'] ?? '';
        // Axios sends boolean false as "false" in query params, which Laravel's
        // boolean validation rule doesn't accept. Use filter_var to handle both
        // true booleans and string representations.
        $unlinkedOnly = filter_var(
            $validated['unlinked_only'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        ) ?? false;
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
            // LIKEの特殊文字をエスケープ
            $escapedSearch = addcslashes($search, '%_\\');
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
            // LIKEの特殊文字をエスケープ
            $escapedSearch = addcslashes($search, '%_\\');
            $query->where(function ($q) use ($escapedSearch) {
                $q->where('title', 'like', "%{$escapedSearch}%")
                    ->orWhere('artist', 'like', "%{$escapedSearch}%");
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
            'title' => 'required|string|max:255',
            'artist' => 'required|string|max:255',
            'spotify_track_id' => 'nullable|string|max:22|regex:/^[a-zA-Z0-9]+$/',
            'spotify_data' => 'nullable|array',
            'force_create' => 'nullable|boolean', // 類似曲があっても強制的に新規登録
            'use_existing_id' => 'nullable|string|exists:songs,id', // 類似曲の中から選択した既存曲ID
        ]);

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

        // 正規化後のテキストで比較するため、全曲を取得して比較
        $allSongs = Song::all();
        foreach ($allSongs as $song) {
            $songNormalizedTitle = TextNormalizer::normalize($song->title);
            $songNormalizedArtist = TextNormalizer::normalize($song->artist);

            if ($songNormalizedTitle === $normalizedTitle && $songNormalizedArtist === $normalizedArtist) {
                return response()->json([
                    'status' => 'exact_match',
                    'song' => $song,
                    'message' => '既に登録されている楽曲マスタが見つかりました。',
                ], 200);
            }
        }

        // 類似度チェック
        $threshold = config('songs.similarity_threshold', 0.75);
        $similarSongs = $this->findSimilarSongs($normalizedTitle, $normalizedArtist, $threshold);

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
     * 類似する楽曲を検索
     */
    private function findSimilarSongs($normalizedTitle, $normalizedArtist, $threshold = 0.75)
    {
        // パフォーマンス最適化：部分一致で候補を絞り込んでから類似度計算
        // 正規化後のタイトル・アーティストの最初の3文字で絞り込み
        $titlePrefix = mb_substr($normalizedTitle, 0, 3);
        $artistPrefix = mb_substr($normalizedArtist, 0, 3);

        $candidateSongs = Song::where(function ($query) use ($titlePrefix, $artistPrefix) {
            $query->where('title', 'like', "{$titlePrefix}%")
                ->orWhere('artist', 'like', "{$artistPrefix}%");
        })->limit(100)->get();

        $similarSongs = [];

        foreach ($candidateSongs as $song) {
            $songNormalizedTitle = TextNormalizer::normalize($song->title);
            $songNormalizedArtist = TextNormalizer::normalize($song->artist);

            // タイトルとアーティスト名の類似度を計算
            $titleSimilarity = $this->calculateSimilarity($normalizedTitle, $songNormalizedTitle);
            $artistSimilarity = $this->calculateSimilarity($normalizedArtist, $songNormalizedArtist);

            // 両方の平均が閾値以上の場合に類似とみなす
            $averageSimilarity = ($titleSimilarity + $artistSimilarity) / 2;

            if ($averageSimilarity >= $threshold) {
                $similarSongs[] = [
                    'song' => $song,
                    'similarity' => round($averageSimilarity * 100, 1),
                    'title_similarity' => round($titleSimilarity * 100, 1),
                    'artist_similarity' => round($artistSimilarity * 100, 1),
                ];
            }
        }

        // 類似度の高い順にソート
        usort($similarSongs, function ($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });

        return $similarSongs;
    }

    /**
     * 2つの文字列の類似度を計算（0.0 ~ 1.0）
     */
    private function calculateSimilarity($str1, $str2)
    {
        if (empty($str1) || empty($str2)) {
            return 0.0;
        }

        // Levenshtein距離を使用（255文字制限あり）
        $maxLen = max(mb_strlen($str1), mb_strlen($str2));
        if ($maxLen === 0) {
            return 1.0;
        }

        // levenshtein()は255文字までしか対応していないため、超える場合は切り詰める
        if (strlen($str1) > 255 || strlen($str2) > 255) {
            $str1 = substr($str1, 0, 255);
            $str2 = substr($str2, 0, 255);
        }

        $distance = levenshtein($str1, $str2);

        // levenshtein()が失敗した場合（-1を返す）は類似度0とする
        if ($distance === -1) {
            return 0.0;
        }

        $similarity = 1 - ($distance / $maxLen);

        return max(0.0, min(1.0, $similarity));
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
            $mapping = TimestampSongMapping::where('normalized_text', $validated['normalized_text'])->first();

            if ($mapping) {
                // 既存レコードを更新（IDは変更しない）
                $mapping->update([
                    'song_id' => $validated['song_id'],
                    'is_not_song' => false,
                    'is_manual' => true,
                    'confidence' => 1.0,
                ]);
            } else {
                // 新規レコードを作成
                TimestampSongMapping::create([
                    'id' => Str::ulid(),
                    'normalized_text' => $validated['normalized_text'],
                    'song_id' => $validated['song_id'],
                    'is_not_song' => false,
                    'is_manual' => true,
                    'confidence' => 1.0,
                ]);
            }
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
            $mapping = TimestampSongMapping::where('normalized_text', $validated['normalized_text'])->first();

            if ($mapping) {
                // 既存レコードを更新（IDは変更しない）
                $mapping->update([
                    'song_id' => null,
                    'is_not_song' => true,
                    'is_manual' => true,
                    'confidence' => 1.0,
                ]);
            } else {
                // 新規レコードを作成
                TimestampSongMapping::create([
                    'id' => Str::ulid(),
                    'normalized_text' => $validated['normalized_text'],
                    'song_id' => null,
                    'is_not_song' => true,
                    'is_manual' => true,
                    'confidence' => 1.0,
                ]);
            }
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
            $clientId = config('services.spotify.client_id');
            $clientSecret = config('services.spotify.client_secret');

            if (! $clientId || ! $clientSecret) {
                return response()->json([
                    'error' => 'Spotify API credentials are not configured.',
                ], 500);
            }

            $spotifyService = new SpotifyService;

            // 認証処理
            try {
                $spotifyService->authenticate($clientId, $clientSecret);
            } catch (\Exception $e) {
                \Log::error('Spotify authentication failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                return response()->json([
                    'error' => 'Spotify API authentication failed. Please check your credentials.',
                ], 500);
            }

            // 検索処理
            try {
                $tracks = $spotifyService->searchTracks(
                    $validated['query'],
                    $validated['limit'] ?? 10
                );
            } catch (\Exception $e) {
                \Log::error('Spotify search failed', [
                    'query' => $validated['query'],
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                return response()->json([
                    'error' => 'Spotify API search failed. Please try again later.',
                ], 500);
            }

            return response()->json($tracks);
        } catch (\Exception $e) {
            \Log::error('Unexpected error in searchSpotify', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'An unexpected error occurred.',
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
