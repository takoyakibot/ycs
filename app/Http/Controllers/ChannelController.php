<?php

namespace App\Http\Controllers;

use App\Helpers\CharacterCategorizer;
use App\Helpers\TextNormalizer;
use App\Helpers\ValidationHelper;
use App\Models\Channel;
use App\Models\Song;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Services\GetArchiveService;
use App\Services\TimestampService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChannelController extends Controller
{
    protected $getArchiveService;

    protected $timestampService;

    public function __construct(GetArchiveService $getArchiveService, TimestampService $timestampService)
    {
        $this->getArchiveService = $getArchiveService;
        $this->timestampService = $timestampService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // チャンネル情報を取得して表示
        $page = config('utils.page');
        $paginatedChannels = Channel::paginate($page);

        // 各チャンネルに最終更新日を追加
        $channelsWithLastUpdate = collect($paginatedChannels->items())->map(function ($channel) {
            $channel->last_refresh_at = $channel->archives()->max('updated_at');

            return $channel;
        });

        $channels = [
            'data' => $channelsWithLastUpdate->toArray(),
            'current_page' => $paginatedChannels->currentPage(),
            'last_page' => $paginatedChannels->lastPage(),
            'per_page' => $paginatedChannels->perPage(),
            'total' => $paginatedChannels->total(),
        ];

        return view('channels.index', compact('channels'));
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        // チャンネル情報を取得して表示
        $channel = Channel::where('handle', $id)->firstOrFail();

        return view('channels.show', compact('channel'));
    }

    public function fetchArchives(string $id, Request $request)
    {
        $archives = $this->getArchiveService->getArchives(
            $id,
            (string) $request->query('search', ''),
            (string) $request->query('visible', ''),
            (string) $request->query('ts', '')
        )
            ->appends($request->query());

        // 楽曲マッピング情報を付与
        $archivesArray = $archives->toArray();

        // 全タイムスタンプの正規化テキストと個別マッピングのsong_idを収集
        $allNormalizedTexts = [];
        $allIndividualSongIds = [];
        foreach ($archivesArray['data'] as $archive) {
            if (isset($archive['ts_items_display'])) {
                foreach ($archive['ts_items_display'] as $tsItem) {
                    if (! empty($tsItem['text'])) {
                        $allNormalizedTexts[] = TextNormalizer::normalize($tsItem['text']);
                    }
                    if (! empty($tsItem['song_id'])) {
                        $allIndividualSongIds[] = $tsItem['song_id'];
                    }
                }
            }
        }

        // 早期リターン: タイムスタンプがない場合
        if (empty($allNormalizedTexts) && empty($allIndividualSongIds)) {
            return response()->json($archivesArray);
        }

        // 一度にすべてのマッピングを取得
        try {
            $mappings = TimestampSongMapping::whereIn('normalized_text', array_unique($allNormalizedTexts))
                ->with('song')
                ->get()
                ->keyBy('normalized_text');
        } catch (\Exception $e) {
            // ログにエラーを記録
            \Log::error('Failed to fetch song mappings in fetchArchives', [
                'error' => $e->getMessage(),
                'channel_id' => $id,
            ]);

            // エラー発生時は空のコレクションを返して処理を継続
            $mappings = collect();
        }

        // 個別マッピング（ts_items.song_id）の楽曲情報を一括取得（#724）
        $individualSongs = ! empty($allIndividualSongIds)
            ? Song::whereIn('id', array_unique($allIndividualSongIds))->get()->keyBy('id')
            : collect();

        // 「楽曲ではない」タイムスタンプを除外してから楽曲情報を追加
        foreach ($archivesArray['data'] as &$archive) {
            if (isset($archive['ts_items_display'])) {
                // 「楽曲ではない」とマークされたタイムスタンプを除外（個別マッピングがある場合は常に表示 #724）
                $archive['ts_items_display'] = array_values(array_filter($archive['ts_items_display'], function ($tsItem) use ($mappings) {
                    if (! empty($tsItem['song_id'])) {
                        return true;
                    }
                    if (empty($tsItem['text'])) {
                        return true;
                    }
                    $normalizedText = TextNormalizer::normalize($tsItem['text']);
                    $mapping = $mappings->get($normalizedText);

                    return ! ($mapping && $mapping->is_not_song);
                }));

                // 残ったタイムスタンプに楽曲情報を追加（個別マッピング優先 #724）
                foreach ($archive['ts_items_display'] as &$tsItem) {
                    if (! empty($tsItem['song_id'])) {
                        $song = $individualSongs[$tsItem['song_id']] ?? null;
                        if ($song) {
                            $tsItem['song'] = [
                                'title' => $song->title,
                                'artist' => $song->artist,
                                'spotify_track_id' => ValidationHelper::validateSpotifyTrackId($song->spotify_track_id),
                            ];
                        } else {
                            $tsItem['song'] = null;
                        }
                    } elseif (! empty($tsItem['text'])) {
                        $normalizedText = TextNormalizer::normalize($tsItem['text']);
                        $mapping = $mappings->get($normalizedText);

                        if ($mapping && $mapping->song && $mapping->isConfirmed()) {
                            $tsItem['song'] = [
                                'title' => $mapping->song->title,
                                'artist' => $mapping->song->artist,
                                'spotify_track_id' => ValidationHelper::validateSpotifyTrackId($mapping->song->spotify_track_id),
                            ];
                        } else {
                            $tsItem['song'] = null;
                        }
                    }
                }
            }
        }

        return response()->json($archivesArray);
    }

    /**
     * チャンネルに紐づくタイムスタンプを取得（マッピング情報付き）
     */
    public function fetchTimestamps(string $id, Request $request)
    {
        $channel = Channel::where('handle', $id)->firstOrFail();

        $validated = $request->validate([
            'per_page' => 'integer|min:1|max:100',
            'page' => 'integer|min:1',
            'search' => 'string|max:255',
            'index' => ['nullable', 'string', Rule::in(CharacterCategorizer::getAllCategories())],
            'published_from' => 'nullable|date_format:Y-m-d',
            'published_to' => 'nullable|date_format:Y-m-d',
        ]);

        $result = $this->timestampService->getTimestampsWithMapping(
            $channel,
            $validated['per_page'] ?? 50,
            $validated['page'] ?? 1,
            $validated['search'] ?? '',
            $validated['index'] ?? '',
            $validated['published_from'] ?? '',
            $validated['published_to'] ?? ''
        );

        return response()->json($result);
    }

    /**
     * チャンネルのタイムスタンプからランダムに1件取得
     */
    public function fetchRandomTimestamp(string $id, Request $request)
    {
        $channel = Channel::where('handle', $id)->firstOrFail();

        // 一覧と同じ絞り込み条件を受け取り、条件に合致する中からランダムに選ぶ
        $validated = $request->validate([
            'search' => 'nullable|string|max:255',
            'index' => ['nullable', 'string', Rule::in(CharacterCategorizer::getAllCategories())],
            'published_from' => 'nullable|date_format:Y-m-d',
            'published_to' => 'nullable|date_format:Y-m-d',
        ]);

        $excludeVideoId = $request->query('exclude_video_id');
        if ($excludeVideoId !== null && ! preg_match('/^[A-Za-z0-9_-]{11}$/', $excludeVideoId)) {
            $excludeVideoId = null;
        }
        $result = $this->timestampService->getRandomTimestamp(
            $channel,
            50,
            $excludeVideoId,
            $validated['search'] ?? '',
            $validated['index'] ?? '',
            $validated['published_from'] ?? '',
            $validated['published_to'] ?? ''
        );

        if (! $result) {
            return response()->json([
                'error' => 'タイムスタンプが見つかりませんでした',
            ], 404);
        }

        return response()->json($result);
    }

    /**
     * 同じアーカイブ内の次の楽曲タイムスタンプを取得
     */
    public function fetchNextTimestampInArchive(string $id, Request $request)
    {
        // チャンネルの存在確認
        $channel = Channel::where('handle', $id)->firstOrFail();

        $validated = $request->validate([
            'video_id' => ['required', 'string', 'size:11', 'regex:/^[A-Za-z0-9_-]{11}$/'],
            'current_ts_num' => 'required|integer|min:0',
        ]);

        $result = $this->timestampService->getNextTimestampInArchive(
            $channel,
            $validated['video_id'],
            $validated['current_ts_num']
        );

        if (! $result) {
            return response()->json([
                'error' => 'アーカイブ内に次の楽曲がありません',
            ], 404);
        }

        return response()->json($result);
    }

    /**
     * タイムスタンプの検索候補用テキスト一覧を取得
     */
    public function fetchTimestampTexts(string $id)
    {
        $channel = Channel::where('handle', $id)->firstOrFail();

        // ユニークなテキスト一覧を取得（表示対象のみ）
        $texts = $this->buildTimestampQuery($channel)
            ->select('text')
            ->distinct()
            ->pluck('text')
            ->filter()
            ->values()
            ->toArray();

        return response()->json($texts);
    }

    /**
     * タイムスタンプ取得クエリのベースを構築
     *
     * @param  bool  $withArchive  archiveリレーションを事前ロードするか
     */
    private function buildTimestampQuery(Channel $channel, bool $withArchive = false)
    {
        $query = TsItem::query();

        if ($withArchive) {
            $query->with(['archive']);
        }

        return $query
            ->whereHas('archive', function ($q) use ($channel) {
                $q->where('channel_id', $channel->channel_id)
                    ->where('is_display', 1);
            })
            ->whereNotNull('text')
            ->where('text', '!=', '')
            ->where('is_display', 1);
    }

    /**
     * タイムスタンプ一覧をテキストファイルとしてダウンロード
     */
    public function downloadTimestamps(string $id, Request $request)
    {
        // チャンネル取得
        $channel = Channel::where('handle', $id)->firstOrFail();

        // タイムスタンプ取得クエリ（archiveは不要なのでwith()なし）
        $query = $this->buildTimestampQuery($channel);

        // 出力内容を生成（重複を除外、チャンク処理でメモリ効率化）
        $lines = [];
        $seen = [];
        $normalizedTexts = [];

        // チャンク処理でタイムスタンプを取得
        $query->chunk(1000, function ($timestamps) use (&$normalizedTexts, &$seen, &$lines) {
            foreach ($timestamps as $item) {
                $normalizedText = TextNormalizer::normalize($item->text);

                // 重複チェック（正規化テキストで判定）
                if (isset($seen[$normalizedText])) {
                    continue;
                }
                $seen[$normalizedText] = true;
                $normalizedTexts[] = $normalizedText;
                $lines[] = $normalizedText;
            }
        });

        // マッピング情報を取得（バッチ処理で1000件ずつ）
        $mappings = collect();
        try {
            foreach (array_chunk($normalizedTexts, 1000) as $chunk) {
                $batchMappings = TimestampSongMapping::whereIn('normalized_text', $chunk)
                    ->with('song')
                    ->get();
                $mappings = $mappings->merge($batchMappings);
            }
            $mappings = $mappings->keyBy('normalized_text');
        } catch (\Exception $e) {
            \Log::error('Failed to fetch song mappings in downloadTimestamps', [
                'error' => $e->getMessage(),
                'channel_id' => $id,
            ]);
            $mappings = collect();
        }

        // 「楽曲ではない」アイテムを除外
        $lines = array_filter($lines, function ($normalizedText) use ($mappings) {
            $mapping = $mappings->get($normalizedText);

            return ! ($mapping && $mapping->is_not_song);
        });

        // ソート
        sort($lines);

        // BOM付きUTF-8でテキスト生成
        $content = "\xEF\xBB\xBF".implode("\n", $lines);

        // ファイル名生成（安全な文字のみ使用、20文字まで）
        $identifier = $channel->handle ?: $channel->channel_id;
        $safeIdentifier = preg_replace('/[^A-Za-z0-9\-_]/', '', $identifier);

        // 空の場合のフォールバック
        if (empty($safeIdentifier)) {
            $safeIdentifier = 'unknown';
        }

        // 識別子を20文字に制限
        $safeIdentifier = substr($safeIdentifier, 0, 20);

        $filename = 'timestamps_'.$safeIdentifier.'_'.date('Ymd').'.txt';

        return response($content, 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"')
            ->header('Content-Length', strlen($content));
    }
}
