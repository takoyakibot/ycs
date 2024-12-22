<?php

namespace App\Http\Controllers;

use App\Models\Archive;
use App\Models\Channel;
use App\Services\ImageService;
use App\Services\YouTubeService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
            return view('manage.index', compact('api_key_flg'));
        }
        return view('manage.show', compact('channel'));
    }

    public function fetchChannel(Request $request)
    {
        $channels = Channel::all();
        return response()->json($channels);
    }

    public function addChannel(Request $request)
    {
        $request->validate([
            'handle' => 'required|string|unique:channels,handle',
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
        $channel = Channel::where('handle', $id)->firstOrFail();
        $archives = Archive::with('tsItems')
            ->where('channel_id', $channel->channel_id)
            ->get();
        return response()->json($archives);
    }

    public function addAchives($id)
    {
        $channel = Channel::where('handle', $id)->firstOrFail();

        DB::transaction(function () use ($channel) {
            try {
                [$rtn_archives, $rtn_ts_items] = $this->youtubeService
                    ->getArchivesAndTsItems($channel->channel_id);
            } catch (Exception $e) {
                error_log($e->getMessage());
                throw new Exception("youtubeとの接続でエラーが発生しました");
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
}
