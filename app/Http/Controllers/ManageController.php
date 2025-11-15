<?php

namespace App\Http\Controllers;

use App\Exceptions\NotFoundException;
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
        // APIキーまたはGoogle OAuthトークンが存在すればOK
        $user = Auth::user();
        $api_key_flg = ($user->api_key || $user->google_token) ? '1' : '';

        return view('manage.index', compact('api_key_flg'));
    }

    public function show($id)
    {
        // APIキーまたはGoogle OAuthトークンが未登録の場合はチャンネル管理に戻す
        $user = Auth::user();
        $api_key_flg = ($user->api_key || $user->google_token) ? '1' : '';
        // ハンドルが存在しない場合はチャンネル管理に戻す
        $channel = Channel::where('handle', $id)->first();
        if (! $api_key_flg || ! $channel) {
            return redirect()->route('manage.index');
        }
        $crypt_handle = Crypt::encryptString($channel->handle);

        return view('manage.show', compact('channel', 'crypt_handle'));
    }

    public function fetchChannel(Request $request)
    {
        $channels = Channel::all();

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
            error_log($e->getMessage());
            throw new Exception('youtubeとの接続でエラーが発生しました');
        }
        if (! $channel || ! isset($channel['title']) || ! $channel['title']) {
            throw new NotFoundException('チャンネルが存在しません');
        }

        Channel::create([
            'handle' => $request->handle,
            'channel_id' => $channel['channel_id'],
            'title' => $channel['title'],
            'thumbnail' => $channel['thumbnail'],
        ]);

        return response()->json('チャンネルを登録しました');
    }

    public function fetchArchives(string $id, Request $request)
    {
        $archives = $this->getArchiveService->getArchivesForManage(
            $id,
            (string) $request->query('baramutsu', ''),
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

        $this->refreshArchiveService->refreshArchives($channel);

        return response()->json('アーカイブを登録しました');
    }

    // 動画の表示非表示切り替え
    // comment_id = null の場合に動画と判断する
    public function toggleDisplay(Request $request)
    {
        $request->validate([
            'id' => ['required', 'string'],
            'is_display' => ['required', 'in:0,1'],
        ]);

        $newDisplay = DB::transaction(function () use ($request) {
            $new_display = ($request->is_display === '1') ? '0' : '1';
            // archivesとchange_listを更新
            // Archive::where('id', $request->id)->update(['is_display' => $new_display]);
            // return response()->json($new_display);
            $archive = Archive::findOrFail($request->id);
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

    public function fetchComments(Request $request)
    {
        $request->validate([
            'id' => ['required', 'string'],
        ]);
        $videoId = Archive::findOrFail($request->id, ['video_id'])->video_id;
        DB::transaction(function () use ($videoId) {
            // TODO:概要欄の再取得が現状不可能 日々の更新ができるようになれば勝手に更新されるはずなので問題ない？
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
    public function editTimestamps(Request $request)
    {
        $validatedData = $request->validate([
            '*.id' => 'required|string|exists:ts_items,id',
            '*.comment_id' => 'required|string|exists:ts_items,comment_id',
            '*.is_display' => 'required|boolean',
        ]);
        DB::transaction(function () use ($validatedData) {
            // リクエストで渡されたコメントIDに紐づくarchiveを取得
            $commentIds = array_column($validatedData, 'comment_id');
            $tsItem = TsItem::where('comment_id', $commentIds[0])
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

            // validatedData のループ処理
            $lastCommentId = '';
            foreach ($validatedData as $item) {
                // is_display の更新
                TsItem::where('id', $item['id'])->update(['is_display' => $item['is_display']]);

                // comment_id が変わった場合の処理
                // コメント単位に変更リストにレコードを作成する
                if ($lastCommentId !== $item['comment_id']) {
                    ChangeList::create([
                        'channel_id' => $channelId,
                        'video_id' => $videoId,
                        'comment_id' => $item['comment_id'],
                        'is_display' => $item['is_display'],
                    ]);
                }
                $lastCommentId = $item['comment_id'];
            }
        });

        return response()->json(['message' => 'タイムスタンプの編集が完了しました']);
    }
}
