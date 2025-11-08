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

        return response()->json($archives);
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
        $sort = $validated['sort'] ?? 'time_desc';

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
        $mappings = TimestampSongMapping::whereIn('normalized_text', $normalizedTexts)
            ->with('song')
            ->get()
            ->keyBy('normalized_text');

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
                    ] : null,
                    'is_not_song' => $mapping->is_not_song,
                ] : null,
            ];
        });

        // 楽曲名・アーティスト名での検索フィルタリング
        if ($search) {
            $timestampsWithMapping = $timestampsWithMapping->filter(function ($ts) use ($search) {
                // タイムスタンプテキストで一致（既にDBレベルでフィルタ済みだが念のため）
                if (stripos($ts['text'], $search) !== false) {
                    return true;
                }

                // 楽曲紐づけ済みの場合は楽曲名・アーティスト名でも検索
                if ($ts['mapping'] && $ts['mapping']['song']) {
                    $songText = $ts['mapping']['song']['title'].' '.$ts['mapping']['song']['artist'];

                    return stripos($songText, $search) !== false;
                }

                return false;
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
        ]);
    }
}
