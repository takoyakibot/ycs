<?php

namespace App\Http\Controllers;

use App\Models\Archive;
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
        $this->imageService = $imageService;
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
        if (!$api_key_flg || !$channel) {
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
        if (!$channel || !isset($channel['title']) || !$channel['title']) {
            throw new Exception("チャンネルが存在しません");
        }

        Channel::create([
            'handle' => $request->handle,
            'channel_id' => $channel['channel_id'],
            'title' => $channel['title'],
            'thumbnail' => $channel['thumbnail'],
        ]);

        return response()->json("チャンネルを登録しました");
    }

    public function fetchArchives($id)
    {
        $handle = Crypt::decryptString($id);

        $channel = Channel::where('handle', $handle)->firstOrFail();
        $archives = Archive::with('tsItems')
            ->where('channel_id', $channel->channel_id)
            ->orderBy('published_at', 'desc')
            ->get();
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
                // コメントを取得しても
                $archive['is_display'] = (count($archive['ts_items']) > 0);
                unset($archive['description']);
                unset($archive['ts_items']);
            }

            // cascadeでTsItemsも消える
            Archive::where('channel_id', $channel->channel_id)->delete();
            if ($rtn_archives) {
                DB::table('archives')->insert($rtn_archives);
            }
            if ($rtn_ts_items) {
                DB::table('ts_items')->insert($rtn_ts_items);
            }
        });

        return response()->json("アーカイブを登録しました");
    }

    public function toggleDisplay(Request $request)
    {
        $request->validate([
            'id' => ['required', 'string'],
            'is_display' => ['required', 'in:0,1'],
        ]);
        $new_display = ($request->is_display === '1') ? '0' : '1';
        Archive::where('id', $request->id)->update(['is_display' => $new_display]);
        return response()->json($new_display);
    }

    public function fetchComments(Request $request)
    {
        $request->validate([
            'id' => ['required', 'string'],
        ]);
        $archive = Archive::findOrFail($request->id, 'video_id');
        DB::transaction(function () use ($archive) {
            try {
                $ts_items = $this->youtubeService->getTimeStampsFromComments($archive->video_id);
            } catch (Exception $e) {
                error_log($e->getMessage());
                throw new Exception("youtubeとの接続でエラーが発生しました");
            }
            TsItem::where('video_id', $archive->video_id)
                ->where('type', '2')
                ->delete();
            if ($ts_items) {
                DB::table('ts_items')->insert($ts_items);
            }
        });
        $ts_items = TsItem::where('video_id', $archive->video_id)->get();
        return response()->json($ts_items);
    }

    public function editTimestamps(Request $request)
    {
        $validatedData = $request->validate([
            '*.id' => 'required|string|exists:ts_items,id',
            '*.is_display' => 'required|boolean',
        ]);
        foreach ($validatedData as $item) {
            TsItem::where('id', $item['id'])->update(['is_display' => $item['is_display']]);
        }
        return response()->json(['message' => "タイムスタンプの編集が完了しました"]);
    }
}
