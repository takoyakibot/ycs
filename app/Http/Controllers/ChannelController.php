<?php

namespace App\Http\Controllers;

use App\Helpers\TextNormalizer;
use App\Models\Channel;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Services\GetArchiveService;
use Illuminate\Http\Request;

class ChannelController extends Controller
{
    protected $getArchiveService;

    public function __construct(GetArchiveService $getArchiveService)
    {
        $this->getArchiveService = $getArchiveService;
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
            (string) $request->query('baramutsu', ''),
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

        // 各タイムスタンプに楽曲情報を追加
        foreach ($archivesArray['data'] as &$archive) {
            if (isset($archive['ts_items_display'])) {
                foreach ($archive['ts_items_display'] as &$tsItem) {
                    if (! empty($tsItem['text'])) {
                        $normalizedText = TextNormalizer::normalize($tsItem['text']);
                        $mapping = $mappings->get($normalizedText);

                        if ($mapping && $mapping->song && ! $mapping->is_not_song) {
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
        // チャンネル取得
        $channel = Channel::where('handle', $id)->firstOrFail();

        // バリデーション
        $validated = $request->validate([
            'per_page' => 'integer|min:1|max:100',
            'page' => 'integer|min:1',
            'search' => 'string|max:255',
            'sort' => 'string|in:time_desc,time_asc,song_asc,archive_desc',
        ]);

        $perPage = $validated['per_page'] ?? 50;
        $currentPage = $validated['page'] ?? 1;
        $search = $validated['search'] ?? '';
        $sort = $validated['sort'] ?? 'song_asc';

        // タイムスタンプ取得（チャンネルフィルタ付き）
        $query = TsItem::with(['archive'])
            ->whereHas('archive', function ($q) use ($channel) {
                $q->where('channel_id', $channel->channel_id)
                    ->where('is_display', 1);
            })
            ->whereNotNull('text')
            ->where('text', '!=', '')
            ->where('is_display', 1);

        // 検索条件の追加（タイムスタンプテキスト）
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
        try {
            $mappings = TimestampSongMapping::whereIn('normalized_text', $normalizedTexts)
                ->with('song')
                ->get()
                ->keyBy('normalized_text');
        } catch (\Exception $e) {
            // ログにエラーを記録
            \Log::error('Failed to fetch song mappings in fetchTimestamps', [
                'error' => $e->getMessage(),
                'channel_id' => $id,
            ]);

            // エラー発生時は空のコレクションを返して処理を継続
            $mappings = collect();
        }

        // 各タイムスタンプにマッピング情報を追加
        $timestampsWithMapping = $allTimestamps->map(function ($item) use ($mappings) {
            $normalizedText = TextNormalizer::normalize($item->text);
            $mapping = $mappings->get($normalizedText);

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
                'mapping' => $mapping ? [
                    'song' => $mapping->song ? [
                        'title' => $mapping->song->title,
                        'artist' => $mapping->song->artist,
                        'spotify_track_id' => $this->validateSpotifyTrackId($mapping->song->spotify_track_id),
                    ] : null,
                    'is_not_song' => $mapping->is_not_song,
                ] : null,
            ];
        });

        // 楽曲名・アーティスト名での検索フィルタリング
        // ※タイムスタンプテキストはDBレベルでフィルタ済み
        if ($search) {
            $timestampsWithMapping = $timestampsWithMapping->filter(function ($ts) use ($search) {
                // 楽曲紐づけ済みの場合は楽曲名・アーティスト名でも検索
                if ($ts['mapping'] && $ts['mapping']['song']) {
                    $songText = $ts['mapping']['song']['title'].' '.$ts['mapping']['song']['artist'];
                    if (stripos($songText, $search) !== false) {
                        return true;
                    }
                }

                // 楽曲検索で一致しない場合も、テキスト検索でDBに含まれているものは表示
                return true;
            });
        }

        // 「楽曲ではない」タイムスタンプを除外
        $timestampsWithMapping = $timestampsWithMapping->filter(function ($ts) {
            return ! ($ts['mapping'] && $ts['mapping']['is_not_song']);
        })->values();

        // ソート処理
        switch ($sort) {
            case 'time_asc':
                $timestampsWithMapping = $timestampsWithMapping->sortBy('ts_num');
                break;
            case 'time_desc':
                $timestampsWithMapping = $timestampsWithMapping->sortByDesc('ts_num');
                break;
            case 'song_asc':
                $timestampsWithMapping = $timestampsWithMapping->sort(function ($a, $b) {
                    // 楽曲紐づけ済みは楽曲名、未紐づけはテキストでソート
                    $aTitle = $a['mapping']['song']['title'] ?? $a['text'] ?? '';
                    $bTitle = $b['mapping']['song']['title'] ?? $b['text'] ?? '';

                    return strcasecmp($aTitle, $bTitle);
                });
                break;
            case 'archive_desc':
                $timestampsWithMapping = $timestampsWithMapping->sortByDesc(function ($ts) {
                    return $ts['archive']['published_at'];
                });
                break;
        }

        $timestampsWithMapping = $timestampsWithMapping->values();

        // 頭文字インデックスマップを生成（楽曲名ソート時のみ）
        $indexMap = [];
        $availableIndexes = [];
        if ($sort === 'song_asc') {
            foreach ($timestampsWithMapping as $index => $ts) {
                $title = $ts['mapping']['song']['title'] ?? $ts['text'] ?? '';
                if (empty($title)) {
                    continue;
                }

                // 頭文字を取得
                $firstChar = mb_substr($title, 0, 1, 'UTF-8');
                $firstChar = mb_strtoupper($firstChar, 'UTF-8');

                // カテゴリ分け
                $category = $this->categorizeFirstChar($firstChar);

                // まだ記録されていないカテゴリの場合、ページ番号を記録
                if (! isset($indexMap[$category])) {
                    $pageNum = (int) floor($index / $perPage) + 1;
                    $indexMap[$category] = $pageNum;
                    $availableIndexes[] = $category;
                }
            }
        }

        // 手動でページネーション
        $total = $timestampsWithMapping->count();
        $lastPage = (int) ceil($total / $perPage);
        $offset = ($currentPage - 1) * $perPage;
        $items = $timestampsWithMapping->slice($offset, $perPage)->values();

        return response()->json([
            'data' => $items,
            'current_page' => $currentPage,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'total' => $total,
            'index_map' => $indexMap,
            'available_indexes' => $availableIndexes,
        ]);
    }

    /**
     * 頭文字をカテゴリに分類
     */
    private function categorizeFirstChar($char)
    {
        // アルファベット（A-Z）
        if (preg_match('/^[A-Z]$/i', $char)) {
            return strtoupper($char);
        }

        // ひらがな・カタカナ（五十音行に分類）
        $kanaMap = [
            'あ' => ['あ', 'い', 'う', 'え', 'お', 'ア', 'イ', 'ウ', 'エ', 'オ'],
            'か' => ['か', 'き', 'く', 'け', 'こ', 'が', 'ぎ', 'ぐ', 'げ', 'ご',
                'カ', 'キ', 'ク', 'ケ', 'コ', 'ガ', 'ギ', 'グ', 'ゲ', 'ゴ'],
            'さ' => ['さ', 'し', 'す', 'せ', 'そ', 'ざ', 'じ', 'ず', 'ぜ', 'ぞ',
                'サ', 'シ', 'ス', 'セ', 'ソ', 'ザ', 'ジ', 'ズ', 'ゼ', 'ゾ'],
            'た' => ['た', 'ち', 'つ', 'て', 'と', 'だ', 'ぢ', 'づ', 'で', 'ど',
                'タ', 'チ', 'ツ', 'テ', 'ト', 'ダ', 'ヂ', 'ヅ', 'デ', 'ド'],
            'な' => ['な', 'に', 'ぬ', 'ね', 'の', 'ナ', 'ニ', 'ヌ', 'ネ', 'ノ'],
            'は' => ['は', 'ひ', 'ふ', 'へ', 'ほ', 'ば', 'び', 'ぶ', 'べ', 'ぼ',
                'ぱ', 'ぴ', 'ぷ', 'ぺ', 'ぽ',
                'ハ', 'ヒ', 'フ', 'ヘ', 'ホ', 'バ', 'ビ', 'ブ', 'ベ', 'ボ',
                'パ', 'ピ', 'プ', 'ペ', 'ポ'],
            'ま' => ['ま', 'み', 'む', 'め', 'も', 'マ', 'ミ', 'ム', 'メ', 'モ'],
            'や' => ['や', 'ゆ', 'よ', 'ヤ', 'ユ', 'ヨ'],
            'ら' => ['ら', 'り', 'る', 'れ', 'ろ', 'ラ', 'リ', 'ル', 'レ', 'ロ'],
            'わ' => ['わ', 'を', 'ん', 'ワ', 'ヲ', 'ン'],
        ];

        foreach ($kanaMap as $category => $chars) {
            if (in_array($char, $chars)) {
                return $category;
            }
        }

        // 数字（0-9）
        if (preg_match('/^[0-9]$/', $char)) {
            return '0-9';
        }

        // その他（記号など）
        return 'その他';
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
        $query = TsItem::query()
            ->whereHas('archive', function ($q) use ($channel) {
                $q->where('channel_id', $channel->channel_id)
                    ->where('is_display', 1);
            })
            ->whereNotNull('text')
            ->where('text', '!=', '')
            ->where('is_display', 1);

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
        $filename = 'timestamps_'.date('Ymd').'.txt';

        return response($content, 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"')
            ->header('Content-Length', strlen($content));
    }
}
