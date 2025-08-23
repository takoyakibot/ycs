<?php
namespace App\Http\Controllers;

use App\Models\Archive;
use App\Models\Channel;
use Illuminate\Http\Request;

class ChannelController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // チャンネル情報を取得して表示
        $page     = config('utils.page');
        $channels = Channel::paginate($page)->toArray();
        return view('channels.index', compact('channels'));
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        // チャンネル情報を取得して表示
        $channel  = Channel::where('handle', $id)->firstOrFail();
        $archives = $this->getArchives($channel->channel_id, '')->toArray();

        return view('channels.show', compact('channel', 'archives'));
    }

    public function fetchArchives(string $id, Request $request)
    {
        $channel  = Channel::where('handle', $id)->firstOrFail();
        $archives = $this->getArchives($channel->channel_id, (string) $request->query('baramutsu', ''))
            ->appends($request->query());
        return response()->json($archives);
    }

    private function getArchives(string $channel_id, string $params)
    {
        $archives = Archive::with(['tsItemsDisplay' => function ($query) use ($params) {
            return $this->setQueryWhereParams($query, $params);
        }])
            ->where('channel_id', $channel_id)
            ->where('is_display', '1');

        if ($params != '') {
            $archives->whereHas('tsItemsDisplay', function ($query) use ($params) {
                $query = $this->setQueryWhereParams($query, $params);
            });
        }

        return $archives->orderBy('published_at', 'desc')
            ->paginate(config('utils.page'));
    }

    /**
     * 検索ワードとして渡された単語をスペースで分割してand条件の部分一致whereに変換する
     * @param mixed $query
     * @param string $params
     */
    private function setQueryWhereParams($query, string $params)
    {
        if ($params === '') {
            return $query;
        }

        $paramList = preg_split('/\s+|\x{3000}+/u', trim($params), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($paramList as $p) {
            $query->where('text', 'like', "%{$p}%");
        }

        return $query;
    }
}
