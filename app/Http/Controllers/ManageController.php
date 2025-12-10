<?php

namespace App\Http\Controllers;

use App\Exceptions\NotFoundException;
use App\Http\Requests\EditTimestampsRequest;
use App\Http\Requests\FetchCommentsRequest;
use App\Http\Requests\ToggleDisplayRequest;
use App\Jobs\RefreshChannelArchivesJob;
use App\Models\Archive;
use App\Models\ChangeList;
use App\Models\Channel;
use App\Models\TsItem;
use App\Services\GetArchiveService;
use App\Services\ImageService;
use App\Services\RefreshArchiveService;
use App\Services\YouTubeService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ManageController extends Controller
{
    protected $youtubeService;

    protected $imageService;

    protected $refreshArchiveService;

    protected $getArchiveService;

    public function __construct(
        YouTubeService $youtubeService,
        ImageService $imageService,
        RefreshArchiveService $refreshArchiveService,
        GetArchiveService $getArchiveService
    ) {
        $this->youtubeService = $youtubeService;
        $this->imageService = $imageService;
        $this->refreshArchiveService = $refreshArchiveService;
        $this->getArchiveService = $getArchiveService;
    }

    public function index()
    {
        // APIキーが登録済みかチェック
        $user = Auth::user();
        $api_key_flg = $user->api_key ? '1' : '';

        return view('manage.index', compact('api_key_flg'));
    }

    public function show($id)
    {
        // APIキー未登録の場合はチャンネル管理に戻す
        $user = Auth::user();
        $api_key_flg = $user->api_key ? '1' : '';
        // ハンドルが存在しない場合はチャンネル管理に戻す
        $channel = Channel::where('handle', $id)->first();
        if (! $api_key_flg || ! $channel) {
            return redirect()->route('manage.index');
        }

        // 所有権チェック
        if ($channel->user_id !== $user->id) {
            abort(403, 'このチャンネルへのアクセス権限がありません');
        }

        $crypt_handle = Crypt::encryptString($channel->handle);

        return view('manage.show', compact('channel', 'crypt_handle'));
    }

    public function fetchChannel(Request $request)
    {
        // 自分のチャンネルのみ取得
        $channels = Auth::user()->channels()->get();

        return response()->json($channels);
    }

    public function addChannel(Request $request)
    {
        $request->validate([
            'handle' => 'required|string|regex:/^[a-zA-Z0-9_]+$/|unique:channels,handle',
        ]);

        try {
            $channel = $this->youtubeService->getChannelByHandle($request->handle);
        } catch (Exception $e) {
            Log::error('YouTube API error in addChannel', [
                'handle' => $request->handle,
                'error' => $e->getMessage(),
            ]);
            // サービス層からのユーザーフレンドリーなメッセージをそのまま使用
            throw $e;
        }
        if (! $channel || ! isset($channel['title']) || ! $channel['title']) {
            throw new NotFoundException('チャンネルが存在しません');
        }

        try {
            // DB::transaction + unique constraint で安全に処理
            DB::transaction(function () use ($request, $channel) {
                Channel::create([
                    'handle' => $request->handle,
                    'channel_id' => $channel['channel_id'],
                    'title' => $channel['title'],
                    'thumbnail' => $channel['thumbnail'],
                    'user_id' => Auth::id(),
                ]);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // unique constraint 違反を検出
            if ($e->getCode() === '23000') {
                throw new Exception('このチャンネルは既に他のユーザーによって登録されています');
            }
            throw $e;
        }

        return response()->json('チャンネルを登録しました');
    }

    public function fetchArchives(string $id, Request $request)
    {
        // 所有権チェック
        $handle = Crypt::decryptString($id);
        $channel = Channel::where('handle', $handle)->firstOrFail();
        if ($channel->user_id !== Auth::id()) {
            abort(403, 'このチャンネルへのアクセス権限がありません');
        }

        $archives = $this->getArchiveService->getArchivesForManage(
            $id,
            (string) $request->query('search', ''),
            (string) $request->query('visible', ''),
            (string) $request->query('ts', '')
        )
            ->appends($request->query());

        return response()->json($archives);
    }

    public function addArchives(Request $request)
    {
        $request->validate([
            'handle' => 'required|string',
        ]);
        $handle = Crypt::decryptString($request->handle);

        $channel = Channel::where('handle', $handle)->firstOrFail();

        // 所有権チェック
        if ($channel->user_id !== Auth::id()) {
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
            // archivesとchange_listを更新
            // Archive::where('id', $validated['id'])->update(['is_display' => $new_display]);
            // return response()->json($new_display);
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
     * ひとつの動画に対してのタイムスタンプの表示非表示を、コメント単位で設定する（タイムスタンプをコメント単位にまとめるのは、画面側で実施している）
     * デフォルト状態から変わっていない内容は登録しないとか考えたかったけど無駄に複雑になりそうなのでやめよう
     *
     * @return \Illuminate\Http\JsonResponse
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
            // タイムスタンプの編集なので動画（commentId=null）は除き、洗替のために削除する
            // ちなみにcommentId=videoIdのレコードは概要欄のもの
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
                            // アーカイブ更新時にts_item_idで照合できるようにts_text, ts_numを保存
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
