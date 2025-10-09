<?php
namespace App\Services;

use App\Models\Archive;
use App\Models\Channel;
use Illuminate\Support\Facades\Crypt;

class GetArchiveService
{
    public function getArchivesForManage(string $id, string $params, string $visibleFlg, string $tsFlg)
    {
        $handle   = Crypt::decryptString($id);
        $channel  = Channel::where('handle', $handle)->firstOrFail();
        $archives = Archive::with('tsItems');

        $archives = $this->setQueryWhereParams($archives, $params, 'title');

        return $this->getArchiveCommon($archives, $channel->channel_id, $visibleFlg, $tsFlg);
    }

    public function getArchives(string $handle, string $params, string $visibleFlg, string $tsFlg)
    {
        $channel  = Channel::where('handle', $handle)->firstOrFail();
        $archives = Archive::with(['tsItemsDisplay' => function ($query) use ($params) {
            return $this->setQueryWhereParams($query, $params, 'text');
        }]);

        // 「タイムスタンプなし」以外が選ばれている場合
        if ($tsFlg != '2') {
            $archives->whereHas('tsItemsDisplay', function ($query) use ($params) {
                $query = $this->setQueryWhereParams($query, $params, 'text');
            });
        }

        return $this->getArchiveCommon($archives, $channel->channel_id, $visibleFlg, $tsFlg);
    }

    /**
     * getArchive関連で共通の処理を付加する
     * @param mixed $archives
     * @param string $channelId
     * @param string $visibleFlg
     * @param string $tsFlg
     */
    private function getArchiveCommon($archives, string $channelId, string $visibleFlg, string $tsFlg)
    {
        $archives->where('channel_id', $channelId);

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

        $archives->orderBy('published_at', 'desc');

        return $archives->paginate(config('utils.page'));
    }

    /**
     * 検索ワードとして渡された単語をスペースで分割してand条件の部分一致whereに変換する。
     * trim($params)='' の場合は何もしない。
     * @param mixed $query
     * @param string $params
     * @param string $column
     */
    private function setQueryWhereParams($query, string $params, string $column)
    {
        if (trim($params) === '') {
            return $query;
        }

        $paramList = preg_split('/\s+|\x{3000}+/u', trim($params), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($paramList as $p) {
            $query->where($column, 'like', "%{$p}%");
        }

        return $query;
    }
}
