<?php

namespace App\Http\Controllers;

use App\Helpers\TextNormalizer;
use App\Models\Channel;
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
        $channels = [
            'data' => $paginatedChannels->items(),
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

        // 全タイムスタンプの正規化テキストを収集
        $allNormalizedTexts = [];
        foreach ($archivesArray['data'] as $archive) {
            if (isset($archive['ts_items_display'])) {
                foreach ($archive['ts_items_display'] as $tsItem) {
                    if (! empty($tsItem['text'])) {
                        $allNormalizedTexts[] = TextNormalizer::normalize($tsItem['text']);
                    }
                }
            }
        }

        // 早期リターン: タイムスタンプがない場合
        if (empty($allNormalizedTexts)) {
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

        // 「楽曲ではない」タイムスタンプを除外してから楽曲情報を追加
        foreach ($archivesArray['data'] as &$archive) {
            if (isset($archive['ts_items_display'])) {
                // 「楽曲ではない」とマークされたタイムスタンプを除外
                $archive['ts_items_display'] = array_values(array_filter($archive['ts_items_display'], function ($tsItem) use ($mappings) {
                    if (empty($tsItem['text'])) {
                        return true;
                    }
                    $normalizedText = TextNormalizer::normalize($tsItem['text']);
                    $mapping = $mappings->get($normalizedText);

                    return ! ($mapping && $mapping->is_not_song);
                }));

                // 残ったタイムスタンプに楽曲情報を追加
                foreach ($archive['ts_items_display'] as &$tsItem) {
                    if (! empty($tsItem['text'])) {
                        $normalizedText = TextNormalizer::normalize($tsItem['text']);
                        $mapping = $mappings->get($normalizedText);

                        if ($mapping && $mapping->song) {
                            $tsItem['song'] = [
                                'title' => $mapping->song->title,
                                'artist' => $mapping->song->artist,
                                'spotify_track_id' => $this->validateSpotifyTrackId($mapping->song->spotify_track_id),
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

        $allowedIndexes = [
            'ABCDE', 'FGHIJ', 'KLMNO', 'PQRST', 'UVWXYZ',
            '0-9', 'あ', 'か', 'さ', 'た', 'な', 'は', 'ま', 'や', 'ら', 'わ', 'その他',
        ];

        $validated = $request->validate([
            'per_page' => 'integer|min:1|max:100',
            'page' => 'integer|min:1',
            'search' => 'string|max:255',
            'index' => ['nullable', 'string', Rule::in($allowedIndexes)],
        ]);

        $result = $this->timestampService->getTimestampsWithMapping(
            $channel,
            $validated['per_page'] ?? 50,
            $validated['page'] ?? 1,
            $validated['search'] ?? '',
            $validated['index'] ?? ''
        );

        return response()->json($result);
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
     * Spotify Track IDの妥当性を検証
     */
    private function validateSpotifyTrackId(?string $trackId): ?string
    {
        if (! $trackId) {
            return null;
        }

        // Spotify track IDsは22文字の英数字
        if (preg_match('/^[a-zA-Z0-9]{22}$/', $trackId)) {
            return $trackId;
        }

        return null;
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
