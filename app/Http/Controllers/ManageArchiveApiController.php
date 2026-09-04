<?php

namespace App\Http\Controllers;

use App\Exceptions\NotFoundException;
use App\Http\Controllers\Concerns\ManageAccessControl;
use App\Http\Requests\EditTimestampsRequest;
use App\Http\Requests\FetchCommentsRequest;
use App\Http\Requests\ToggleDisplayRequest;
use App\Jobs\RefreshChannelArchivesJob;
use App\Models\Archive;
use App\Models\ChangeList;
use App\Models\Channel;
use App\Models\SubtitleFingerprint;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Models\VideoSubtitle;
use App\Services\DuplicateCommentDetectionService;
use App\Services\GetArchiveService;
use App\Services\RefreshArchiveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ManageArchiveApiController extends Controller
{
    use ManageAccessControl;

    protected $refreshArchiveService;

    protected $getArchiveService;

    protected $duplicateDetectionService;

    public function __construct(
        RefreshArchiveService $refreshArchiveService,
        GetArchiveService $getArchiveService,
        DuplicateCommentDetectionService $duplicateDetectionService
    ) {
        $this->refreshArchiveService = $refreshArchiveService;
        $this->getArchiveService = $getArchiveService;
        $this->duplicateDetectionService = $duplicateDetectionService;
    }

    public function fetchArchives(string $id, Request $request)
    {
        // アクセス権チェック（所有者またはスーパー管理者）
        $handle = Crypt::decryptString($id);
        $channel = Channel::where('handle', $handle)->firstOrFail();
        if (! $this->canAccessChannel($channel)) {
            abort(403, 'このチャンネルへのアクセス権限がありません');
        }

        $archives = $this->getArchiveService->getArchivesForManage(
            $id,
            (string) $request->query('search', ''),
            (string) $request->query('visible', ''),
            (string) $request->query('ts', '')
        )
            ->appends($request->query());

        // ts_itemsのマッピング状態を付加
        $this->appendMappingStatus($archives);

        // 字幕・フィンガープリントの状況を付加
        $this->appendSubtitleStatus($archives);

        // 重複コメントタイムスタンプの件数を付加
        $this->appendDuplicateCommentStatus($archives);

        return response()->json($archives);
    }

    /**
     * アーカイブ一覧に字幕・フィンガープリントの状況を付加
     *
     * ページ内のアーカイブ分をまとめて集計し、N+1クエリを避ける
     */
    private function appendSubtitleStatus($archives): void
    {
        $videoIds = collect($archives->items())->pluck('video_id')->filter()->unique();

        if ($videoIds->isEmpty()) {
            return;
        }

        // 動画ごとの字幕トラック（言語・種別）
        $subtitleTracks = VideoSubtitle::whereIn('video_id', $videoIds)
            ->orderBy('language_code')
            ->orderBy('kind')
            ->get(['video_id', 'language_code', 'kind'])
            ->groupBy('video_id');

        // 動画ごとのフィンガープリント件数
        $fingerprintCounts = SubtitleFingerprint::whereIn('video_id', $videoIds)
            ->selectRaw('video_id, count(*) as cnt')
            ->groupBy('video_id')
            ->pluck('cnt', 'video_id');

        foreach ($archives->items() as $archive) {
            $tracks = $subtitleTracks->get($archive->video_id, collect());

            $archive->subtitle_status = [
                'has_subtitles' => $tracks->isNotEmpty(),
                'subtitle_tracks' => $tracks->map(fn ($t) => [
                    'language_code' => $t->language_code,
                    'kind' => $t->kind,
                ])->values(),
                'fingerprint_count' => (int) $fingerprintCounts->get($archive->video_id, 0),
            ];
        }
    }

    /**
     * アーカイブ一覧のts_itemsにマッピング状態を付加
     */
    private function appendMappingStatus($archives): void
    {
        // 全ts_itemsのnormalized_textを収集
        $normalizedTexts = collect();
        foreach ($archives->items() as $archive) {
            foreach ($archive->tsItems as $tsItem) {
                if ($tsItem->normalized_text) {
                    $normalizedTexts->push($tsItem->normalized_text);
                }
            }
        }

        if ($normalizedTexts->isEmpty()) {
            return;
        }

        // マッピング情報を一括取得
        $mappings = TimestampSongMapping::with('song')
            ->whereIn('normalized_text', $normalizedTexts->unique())
            ->get()
            ->keyBy('normalized_text');

        // 各ts_itemにマッピング情報を付加
        foreach ($archives->items() as $archive) {
            foreach ($archive->tsItems as $tsItem) {
                $mapping = $mappings->get($tsItem->normalized_text);
                $tsItem->mapping_status = MappingStatusHelper::get($mapping);
            }
        }
    }

    /**
     * アーカイブ一覧に重複コメントタイムスタンプの件数を付加
     */
    private function appendDuplicateCommentStatus($archives): void
    {
        $videoIds = collect($archives->items())->pluck('video_id')->filter()->unique()->values()->toArray();

        if (empty($videoIds)) {
            return;
        }

        $counts = $this->duplicateDetectionService->countByVideoIds($videoIds);

        foreach ($archives->items() as $archive) {
            $archive->duplicate_pair_count = $counts[$archive->video_id] ?? 0;
        }
    }

    /**
     * 動画単位の重複コメントタイムスタンプ詳細を取得
     */
    public function detectDuplicateComments(string $videoId)
    {
        $archive = Archive::where('video_id', $videoId)->first();
        if (! $archive) {
            abort(404, 'アーカイブが見つかりません');
        }

        $channel = Channel::where('channel_id', $archive->channel_id)->first();
        if (! $channel || ! $this->canAccessChannel($channel)) {
            abort(403, 'このチャンネルへのアクセス権限がありません');
        }

        $pairs = $this->duplicateDetectionService->detect($videoId);

        return response()->json([
            'video_id' => $videoId,
            'duplicate_pairs' => collect($pairs)->map(fn ($pair) => [
                'item_a' => [
                    'id' => $pair->id_a,
                    'ts_text' => $pair->ts_text_a,
                    'ts_num' => $pair->ts_num_a,
                    'text' => $pair->text_a,
                    'comment_id' => $pair->comment_id_a,
                ],
                'item_b' => [
                    'id' => $pair->id_b,
                    'ts_text' => $pair->ts_text_b,
                    'ts_num' => $pair->ts_num_b,
                    'text' => $pair->text_b,
                    'comment_id' => $pair->comment_id_b,
                ],
                'diff_seconds' => abs($pair->ts_num_a - $pair->ts_num_b),
            ])->values(),
            'total_pairs' => count($pairs),
        ]);
    }

    public function addArchives(Request $request)
    {
        $request->validate([
            'handle' => 'required|string',
        ]);
        $handle = Crypt::decryptString($request->handle);

        $channel = Channel::where('handle', $handle)->firstOrFail();

        // アクセス権チェック（所有者またはスーパー管理者）
        if (! $this->canAccessChannel($channel)) {
            abort(403, 'このチャンネルへのアクセス権限がありません');
        }

        // キュー設定に応じて同期/非同期で実行
        // sync: 同期実行（従来動作）, database/redis等: 非同期実行
        $queueConnection = config('queue.default');

        if ($queueConnection === 'sync') {
            // 同期実行（従来動作）
            $this->refreshArchiveService->refreshArchives($channel);

            return response()->json([
                'message' => 'アーカイブを登録しました',
                'async' => false,
            ]);
        }

        // 非同期実行
        RefreshChannelArchivesJob::dispatch($channel, Auth::id());

        return response()->json([
            'message' => 'アーカイブの更新処理をキューに登録しました。完了までしばらくお待ちください。',
            'async' => true,
        ]);
    }

    // 動画の表示非表示切り替え
    // comment_id = null の場合に動画と判断する
    public function toggleDisplay(ToggleDisplayRequest $request)
    {
        $validated = $request->validated();

        $newDisplay = DB::transaction(function () use ($validated) {
            $new_display = ($validated['is_display'] === '1') ? '0' : '1';
            $archive = Archive::findOrFail($validated['id']);
            $archive->is_display = $new_display;
            $archive->save();
            ChangeList::updateOrCreate(
                [
                    'channel_id' => $archive->channel_id,
                    'video_id' => $archive->video_id,
                    'comment_id' => null,
                ],
                ['is_display' => $new_display]
            );

            return (string) $new_display;
        });

        return response()->json($newDisplay);
    }

    public function fetchComments(FetchCommentsRequest $request)
    {
        $validated = $request->validated();
        $videoId = Archive::findOrFail($validated['id'], ['video_id'])->video_id;
        DB::transaction(function () use ($videoId) {
            // コメント欄のタイムスタンプのみ再取得
            // 概要欄のタイムスタンプはrefreshArchives()でアーカイブ更新時に自動取得される
            $this->refreshArchiveService->refreshTimeStampsFromComments($videoId);
        });
        $ts_items = TsItem::where('video_id', $videoId)->orderBy('comment_id')->get();

        return response()->json($ts_items);
    }

    /**
     * 画面からのリクエストでタイムスタンプの表示非表示を編集する
     */
    public function editTimestamps(EditTimestampsRequest $request)
    {
        $validatedData = $request->validated();
        if (empty($validatedData)) {
            throw new \InvalidArgumentException('タイムスタンプデータが空です');
        }
        DB::transaction(function () use ($validatedData) {
            // リクエストで渡されたタイムスタンプIDに紐づくarchiveを取得
            $firstItemId = $validatedData[0]['id'];
            $tsItem = TsItem::where('id', $firstItemId)
                ->with(['archive'])->first();
            if (! $tsItem) {
                throw new NotFoundException('tsItem is not found');
            }

            // 取得したarchiveからchannelIdとvideoIdを取得
            $channelId = $tsItem->archive->channel_id;
            $videoId = $tsItem->video_id;
            if (! $channelId || ! $videoId) {
                throw new NotFoundException('channelId or videoId is not found');
            }

            // 変更リストの削除 videoIdが一致し、commentIdがnull以外のものを削除
            ChangeList::where('video_id', $videoId)
                ->whereNotNull('comment_id')
                ->delete();

            // ts_item単位の設定保存時に必要なts_text, ts_numを取得するため、
            // 対象のTsItemをあらかじめ全件ロード
            $tsItemIds = collect($validatedData)->pluck('id')->toArray();
            $tsItemsById = TsItem::whereIn('id', $tsItemIds)
                ->get()
                ->keyBy('id');

            // is_display=1 と is_display=0 でグループ化してバッチ更新
            $displayItemIds = collect($validatedData)->where('is_display', '1')->pluck('id')->toArray();
            $hideItemIds = collect($validatedData)->where('is_display', '0')->pluck('id')->toArray();

            if (! empty($displayItemIds)) {
                TsItem::whereIn('id', $displayItemIds)->update(['is_display' => '1']);
            }
            if (! empty($hideItemIds)) {
                TsItem::whereIn('id', $hideItemIds)->update(['is_display' => '0']);
            }

            // コメントごとにグループ化
            $groupedByComment = collect($validatedData)->groupBy('comment_id');

            foreach ($groupedByComment as $commentId => $items) {
                // コメント内の全タイムスタンプのis_displayを取得
                $displayValues = $items->pluck('is_display')->unique();

                if ($displayValues->count() === 1) {
                    // 全て同じ設定→コメント単位でchange_listを作成
                    ChangeList::create([
                        'channel_id' => $channelId,
                        'video_id' => $videoId,
                        'comment_id' => $commentId,
                        'ts_item_id' => null,
                        'is_display' => $displayValues->first(),
                    ]);
                } else {
                    // 異なる設定がある→タイムスタンプ単位でchange_listを作成
                    foreach ($items as $item) {
                        $tsItemModel = $tsItemsById->get($item['id']);
                        ChangeList::create([
                            'channel_id' => $channelId,
                            'video_id' => $videoId,
                            'comment_id' => $commentId,
                            'ts_item_id' => $item['id'],
                            'is_display' => $item['is_display'],
                            'ts_text' => $tsItemModel?->ts_text,
                            'ts_num' => $tsItemModel?->ts_num,
                        ]);
                    }
                }
            }
        });

        return response()->json(['message' => 'タイムスタンプの編集が完了しました']);
    }
}
