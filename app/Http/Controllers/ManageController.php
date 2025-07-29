<?php
namespace App\Http\Controllers;

use App\Models\Archive;
use App\Models\ChangeList;
use App\Models\Channel;
use App\Models\TsItem;
use App\Services\ImageService;
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

    public function __construct(YouTubeService $youtubeService, ImageService $imageService)
    {
        $this->youtubeService = $youtubeService;
        $this->imageService   = $imageService;
    }

    public function index()
    {
        $api_key_flg = Auth::user()->api_key ? '1' : '';
        return view('manage.index', compact('api_key_flg'));
    }

    public function show($id)
    {
        // APIキー未登録の場合はチャンネル管理に戻す
        $api_key_flg = Auth::user()->api_key ? '1' : '';
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
            throw new Exception("youtubeとの接続でエラーが発生しました");
        }
        if (! $channel || ! isset($channel['title']) || ! $channel['title']) {
            throw new Exception("チャンネルが存在しません");
        }

        Channel::create([
            'handle'     => $request->handle,
            'channel_id' => $channel['channel_id'],
            'title'      => $channel['title'],
            'thumbnail'  => $channel['thumbnail'],
        ]);

        return response()->json("チャンネルを登録しました");
    }

    public function fetchArchives($id)
    {
        $handle = Crypt::decryptString($id);

        $channel  = Channel::where('handle', $handle)->firstOrFail();
        $archives = Archive::with('tsItems')
            ->where('channel_id', $channel->channel_id)
            ->orderBy('published_at', 'desc')
            ->paginate(config('utils.page'));
        return response()->json($archives);
    }

    public function addArchives(Request $request)
    {
        $request->validate([
            'handle' => 'required|string',
        ]);
        $handle = Crypt::decryptString($request->handle);

        $channel = Channel::where('handle', $handle)->firstOrFail();

        DB::transaction(function () use ($channel) {
            // 1.archivesとts_itemsの取得および整形
            try {
                $rtn_archives = $this->youtubeService
                    ->getArchivesAndTsItems($channel->channel_id);
            } catch (Exception $e) {
                error_log($e->getMessage());
                throw new Exception("youtubeとの接続でエラーが発生しました");
            }
            $rtn_ts_items = [];
            foreach ($rtn_archives as &$archive) {
                foreach ($archive['ts_items'] as $ts_item) {
                    $rtn_ts_items[] = $ts_item;
                }
                unset($archive['description']);
                unset($archive['ts_items']);
            }

            // 2.一度関連情報を削除（cascadeでTsItemsも消える）
            Archive::where('channel_id', $channel->channel_id)->delete();

            // 3.一旦DBに登録する
            if ($rtn_archives) {
                // 一気にやるとヤバなので100件くらいずつ登録
                $chunked = array_chunk($rtn_archives, 100);
                foreach ($chunked as $chunk) {
                    DB::table('archives')->insert($chunk);
                }
            }
            if ($rtn_ts_items) {
                $chunked = array_chunk($rtn_ts_items, 100);
                foreach ($chunked as $chunk) {
                    DB::table('ts_items')->insert($chunk);
                }
            }
            // 以下でSQLを実行
            // 4.表示非表示の履歴情報を反映させつつ不要な情報を削除していく
            // 4.1.履歴でコメントを取得しているが現時点ではコメントがない場合、コメントを取得
            // 4.1.1.ts_itemsにcommentがない、かつchangeListにcommentが存在するvideo_idを取得
            $results = DB::select("
                SELECT t1.video_id
                FROM archives t1
                WHERE
                    NOT EXISTS (
                        SELECT 1
                        FROM ts_items t2
                        WHERE t2.video_id = t1.video_id
                        AND t2.type = '2'
                    )
                    AND EXISTS (
                        SELECT 1
                        FROM change_list t3
                        WHERE t3.video_id = t1.video_id
                        AND t3.comment_id IS NOT NULL
                        AND t3.comment_id <> t3.video_id
                    )
                    AND t1.channel_id = ?
            ", [$channel->channel_id]);

            // 4.1.2.取得したvideo_id（コメントを取得する必要のあるアーカイブ）について、コメントを洗替え
            foreach ($results as $result) {
                $video_id = $result->video_id;
                try {
                    $this->refreshTimeStampsFromComments($video_id);
                } catch (Exception $e) {
                    error_log($e->getMessage());
                    throw new Exception("youtubeとの接続でエラーが発生しました");
                }
            }

            // 4.2.履歴情報から、タイムスタンプの表示非表示を反映させる
            // change_list.is_displayが登録されている値と異なる場合、ts_itemsに反映
            // TODO:全件対象にしちゃってるのがやや気になるが悪さはしないので一旦ステイ
            DB::statement("
                UPDATE ts_items t1
                INNER JOIN change_list t2
                  ON t2.video_id = t1.video_id
                  AND t2.comment_id = t1.comment_id
                  AND t2.channel_id = ?
                SET t1.is_display = t2.is_display
                WHERE t1.is_display <> t2.is_display
            ", [$channel->channel_id]);

            // 4.3.履歴情報から、動画の表示非表示を反映させる
            // change_list.is_displayが登録されている値と異なる場合、archivesに反映
            DB::statement("
                UPDATE archives t1
                INNER JOIN change_list t2
                  ON t2.video_id = t1.video_id
                  AND t2.comment_id IS NULL
                SET t1.is_display = t2.is_display
                WHERE t1.is_display <> t2.is_display
                  AND t1.channel_id = ?
            ", [$channel->channel_id]);

            // 4.4.不要な履歴は削除する
            // archivesとts_itemsを外部結合したときに、結合先が存在しないchange_listを削除
            DB::statement("
                DELETE t1 FROM change_list t1
                LEFT JOIN ts_items t2 ON t2.video_id = t1.video_id AND t2.comment_id = t1.comment_id
                LEFT JOIN archives t3 ON t3.video_id = t1.video_id AND t1.comment_id IS NULL
                WHERE t1.channel_id = ?
                    AND
                    (
                        (
                            t2.id IS NOT NULL AND t2.is_display = t1.is_display
                        )
                        OR (
                            t2.id IS NULL AND t1.comment_id IS NOT NULL
                        )
                        OR (
                            t3.id IS NOT NULL AND t1.comment_id IS NULL AND t3.is_display = t1.is_display
                        )
                        OR (
                            t1.comment_id IS NULL AND t3.id IS NULL
                        )
                    )
            ", [$channel->channel_id]);
        });

        return response()->json("アーカイブを登録しました");
    }

    // 動画の表示非表示切り替え
    // comment_id = null の場合に動画と判断する
    public function toggleDisplay(Request $request)
    {
        $request->validate([
            'id'         => ['required', 'string'],
            'is_display' => ['required', 'in:0,1'],
        ]);

        $newDisplay = DB::transaction(function () use ($request) {
            $new_display = ($request->is_display === '1') ? '0' : '1';
            // archivesとchange_listを更新
            // Archive::where('id', $request->id)->update(['is_display' => $new_display]);
            // return response()->json($new_display);
            $archive             = Archive::findOrFail($request->id);
            $archive->is_display = $new_display;
            $archive->save();
            ChangeList::updateOrCreate(
                [
                    'channel_id' => $archive->channel_id,
                    'video_id'   => $archive->video_id,
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
            $this->refreshTimeStampsFromComments($videoId);
        });
        $ts_items = TsItem::where('video_id', $videoId)->orderBy('comment_id')->get();
        return response()->json($ts_items);
    }

    /**
     * コメントを取得して、現在登録されているコメントを削除して再登録
     * @param mixed $videoId
     * @throws \Exception
     * @return void
     */
    private function refreshTimeStampsFromComments($videoId)
    {
        try {
            $ts_items = $this->youtubeService->getTimeStampsFromComments($videoId);
        } catch (Exception $e) {
            error_log($e->getMessage());
            throw new Exception("youtubeとの接続でエラーが発生しました");
        }
        TsItem::where('video_id', $videoId)
            ->where('type', '2')
            ->delete();
        if ($ts_items) {
            DB::table('ts_items')->insert($ts_items);
        }
    }

    /**
     * 画面からのリクエストでタイムスタンプの表示非表示を編集する
     * デフォルト状態から変わっていない内容は登録しないとか考えたかったけど無駄に複雑になりそうなのでやめよう
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function editTimestamps(Request $request)
    {
        $validatedData = $request->validate([
            '*.id'         => 'required|string|exists:ts_items,id',
            '*.comment_id' => 'required|string|exists:ts_items,comment_id',
            '*.is_display' => 'required|boolean',
        ]);
        DB::transaction(function () use ($validatedData) {
            // リクエストで渡されたコメントIDに紐づくarchiveを取得
            $commentIds = array_column($validatedData, 'comment_id');
            $tsItem     = TsItem::where('comment_id', $commentIds[0])
                ->with(['archive'])->first();
            if (! $tsItem) {return;}

            // 取得したarchiveからchannelIdとvideoIdを取得
            $channelId = $tsItem->archive->channel_id;
            $videoId   = $tsItem->video_id;
            if (! $channelId || ! $videoId) {return;}

            // 変更リストの削除 videoIdが一致し、commentIdがnull以外のものを削除
            // タイムスタンプの編集なので動画（commentId=null）は除き、洗替のために削除する
            ChangeList::where('video_id', $videoId)
                ->whereNotNull('comment_id')
                ->delete();

            // validatedData のループ処理
            $lastCommentId = '';
            foreach ($validatedData as $item) {
                // is_display の更新
                TsItem::where('id', $item['id'])->update(['is_display' => $item['is_display']]);

                // comment_id が変わった場合の処理
                if ($lastCommentId !== $item['comment_id']) {
                    ChangeList::create([
                        'channel_id' => $channelId,
                        'video_id'   => $videoId,
                        'comment_id' => $item['comment_id'],
                        'is_display' => $item['is_display'],
                    ]);
                }
                $lastCommentId = $item['comment_id'];
            }
        });
        return response()->json(['message' => "タイムスタンプの編集が完了しました"]);
    }
}
