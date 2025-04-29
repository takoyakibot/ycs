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

            // cascadeでTsItemsも消える
            Archive::where('channel_id', $channel->channel_id)->delete();
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
            // ts_itemsにcommentがない、かつchangeListにcommentが存在する場合、コメントを取得
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
            ");
            // コメントが取得されていないアーカイブについて、コメントを取得
            foreach ($results as $result) {
                $video_id = $result->video_id;
                try {
                    $this->getComments($video_id);
                } catch (Exception $e) {
                    error_log($e->getMessage());
                    throw new Exception("youtubeとの接続でエラーが発生しました");
                }
            }
            // 個別のクエリを発行
            // change_list.is_displayをts_itemsに反映
            DB::statement("
                UPDATE ts_items t1
                JOIN change_list t2 ON t2.video_id = t1.video_id and t2.comment_id = t1.comment_id
                SET t1.is_display = t2.is_display
                where t1.is_display <> t2.is_display
            ");
            // change_list.is_displayををarchivesに反映
            DB::statement("
                UPDATE archives t1
                JOIN change_list t2 ON t2.video_id = t1.video_id and t2.comment_id IS NULL
                SET t1.is_display = t2.is_display
                where t1.is_display <> t2.is_display
            ");
            // archivesとts_itemsに存在しないchange_listを削除
            DB::statement("
                DELETE t1 FROM change_list t1
                LEFT JOIN ts_items t2 ON t2.video_id = t1.video_id AND t2.comment_id = t1.comment_id
                LEFT JOIN archives t3 ON t3.video_id = t1.video_id AND t1.comment_id IS NULL
                WHERE
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
            ");
        });

        return response()->json("アーカイブを登録しました");
    }

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
            $this->getComments($videoId);
        });
        $ts_items = TsItem::where('video_id', $videoId)->orderBy('comment_id')->get();
        return response()->json($ts_items);
    }

    private function getComments($videoId)
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

    public function editTimestamps(Request $request)
    {
        $validatedData = $request->validate([
            '*.id'         => 'required|string|exists:ts_items,id',
            '*.comment_id' => 'required|string|exists:ts_items,comment_id',
            '*.is_display' => 'required|boolean',
        ]);
        DB::transaction(function () use ($validatedData) {
            // 必要な tsItems を一括取得
            $commentIds = array_column($validatedData, 'comment_id');
            $tsItem     = TsItem::where('comment_id', $commentIds[0])
                ->with(['archive'])->first();
            if (! $tsItem) {return;}

            $channelId = $tsItem->archive->channel_id;
            $videoId   = $tsItem->video_id;

            if (! $channelId || ! $videoId) {return;}
            // 変更リストの削除 videoIdが一致し、commentIdがnull以外のものを削除
            ChangeList::where('video_id', $videoId)
                ->where('comment_id', '!=', null)
                ->delete();

            // データベースクエリを削減
            $tsItems = TsItem::where('video_id', $videoId)->get();

            // comment_id ごとの出現回数を事前に計算（comment_idは文字列）
            $countByCommentId = [];
            foreach ($tsItems as $item) {
                $commentId                    = $item['comment_id'];
                $countByCommentId[$commentId] = ($countByCommentId[$commentId] ?? 0) + 1;
            }

            $maxCount              = max($countByCommentId);
            $mostFrequentCommentId = array_keys($countByCommentId, $maxCount, true)[0] ?? null;

            // validatedData のループ処理
            $lastCommentId = '';
            foreach ($validatedData as $item) {
                // is_display の更新
                TsItem::where('id', $item['id'])->update(['is_display' => $item['is_display']]);

                // comment_id が変わった場合の処理
                if ($lastCommentId !== $item['comment_id']) {
                    // 最大件数が2以上でtrue、またはそれ以外のコメントがfalseなら、デフォルト状態なので変更リストには登録しない
                    $isDefault = ($item['comment_id'] === $mostFrequentCommentId && $maxCount >= 2 && $item['is_display'])
                        || ($item['comment_id'] !== $mostFrequentCommentId && ! $item['is_display']);
                    if (! $isDefault) {
                        ChangeList::create([
                            'channel_id' => $channelId,
                            'video_id'   => $videoId,
                            'comment_id' => $item['comment_id'],
                            'is_display' => $item['is_display'],
                        ]);
                    }
                }
                $lastCommentId = $item['comment_id'];
            }
        });
        return response()->json(['message' => "タイムスタンプの編集が完了しました"]);
    }
}
