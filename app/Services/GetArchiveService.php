<?php

namespace App\Services;

use App\Models\Archive;
use App\Models\Channel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GetArchiveService
{
    public function getArchivesForManage(string $id, string $params, string $visibleFlg, string $tsFlg, string $mappingFlg = '')
    {
        $handle = Crypt::decryptString($id);
        $channel = Channel::where('handle', $handle)->firstOrFail();
        $archives = Archive::with('tsItems');

        $archives = $this->setQueryWhereParams($archives, $params, 'title');

        return $this->getArchiveCommon($archives, $channel->channel_id, $visibleFlg, $tsFlg, $mappingFlg);
    }

    public function getArchivesForManageAll(string $params, string $visibleFlg, string $tsFlg, string $mappingFlg, array $channelIds)
    {
        $archives = Archive::with(['tsItems', 'channel'])->whereIn('channel_id', $channelIds);

        $archives = $this->setQueryWhereParams($archives, $params, 'title');

        return $this->getArchiveCommon($archives, null, $visibleFlg, $tsFlg, $mappingFlg);
    }

    public function getArchives(string $handle, string $params, string $visibleFlg, string $tsFlg)
    {
        $this->logSearch($handle, ['params' => $params, 'visible' => $visibleFlg, 'ts' => $tsFlg]);

        $channel = Channel::where('handle', $handle)->firstOrFail();
        $archives = Archive::with(['tsItemsDisplay' => function ($query) use ($params) {
            return $this->setQueryWhereParams($query, $params, 'text');
        }]);

        // 「タイムスタンプなし」以外が選ばれている場合
        // ifの中に入った時点でwhereHasが効いてしまうので、絞り込みをしない場合はifには入らないようにする
        if (trim($params) != '' && $tsFlg != '2') {
            $archives->whereHas('tsItemsDisplay', function ($query) use ($params) {
                $query = $this->setQueryWhereParams($query, $params, 'text');
            });
        }

        return $this->getArchiveCommon($archives, $channel->channel_id, $visibleFlg, $tsFlg);
    }

    /**
     * getArchive関連で共通の処理を付加する
     *
     * @param  mixed  $archives
     */
    private function getArchiveCommon($archives, ?string $channelId, string $visibleFlg, string $tsFlg, string $mappingFlg = '')
    {
        if ($channelId !== null) {
            $archives->where('channel_id', $channelId);
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

        // マッピング状態
        if ($mappingFlg === '1') {
            // 未紐付TSあり: 表示中のts_itemsにマッピングがないものがある
            $archives->whereHas('tsItems', function ($q) {
                $q->where('is_display', '1')
                    ->whereNotExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('timestamp_song_mappings')
                            ->whereColumn('timestamp_song_mappings.normalized_text', 'ts_items.normalized_text');
                    });
            });
        } elseif ($mappingFlg === '2') {
            // 全て紐付済: 表示中のts_itemsが全て紐付済み（未紐付が0件）
            $archives->whereHas('tsItemsDisplay')
                ->whereDoesntHave('tsItems', function ($q) {
                    $q->where('is_display', '1')
                        ->whereNotExists(function ($sub) {
                            $sub->select(DB::raw(1))
                                ->from('timestamp_song_mappings')
                                ->whereColumn('timestamp_song_mappings.normalized_text', 'ts_items.normalized_text');
                        });
                });
        }

        $archives->orderBy('published_at', 'desc');

        return $archives->paginate(config('utils.page'));
    }

    /**
     * 検索ワードとして渡された単語をスペースで分割してand条件の部分一致whereに変換する。
     * trim($params)='' の場合は何もしない。
     *
     * @param  mixed  $query
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

    private function logSearch(string $s, mixed $c = [])
    {
        Log::channel('search')->info($s, $c);
    }
}
