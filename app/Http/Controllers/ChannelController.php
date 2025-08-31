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
        $channel = Channel::where('handle', $id)->firstOrFail();
        return view('channels.show', compact('channel'));
    }

    public function fetchArchives(string $id, Request $request)
    {
        $archives = $this->getArchives($id,
            (string) $request->query('baramutsu', ''),
            (string) $request->query('visible', ''),
            (string) $request->query('ts', '')
        )
            ->appends($request->query());
        return response()->json($archives);
    }

    private function getArchives(string $handle, string $params, string $visibleFlg, string $tsFlg)
    {
        $channel  = Channel::where('handle', $handle)->firstOrFail();
        $archives = Archive::with(['tsItemsDisplay' => function ($query) use ($params) {
            return $this->setQueryWhereParams($query, $params);
        }])
            ->where('channel_id', $channel->channel_id);

        // 検索ワードがある場合
        if ($params != '' && $tsFlg != '2') {
            $archives->whereHas('tsItemsDisplay', function ($query) use ($params) {
                $query = $this->setQueryWhereParams($query, $params);
            });
        }

        // 表示非表示
        if ($visibleFlg === '1') {
            // 非表示のみ
            $archives->where('is_display', '0');
        } elseif ($visibleFlg != '2') {
            // 絞り込みなし以外（表示のみ）
            $archives->where('is_display', '1');
        }

        // タイムスタンプ
        if ($tsFlg === '1') {
            // 有のみ
            $archives->whereHas('tsItemsDisplay');
        } elseif ($tsFlg === '2') {
            // 無のみ
            $archives->whereDoesntHave('tsItemsDisplay');
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
