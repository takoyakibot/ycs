<?php

namespace App\Http\Controllers;

class MappingStatusHelper
{
    /**
     * マッピング状態を判定して返す
     *
     * @return array{status: string, label: string, song_info: string|null}
     */
    public static function get($mapping): array
    {
        if (! $mapping) {
            return [
                'status' => 'unlinked',
                'label' => '未紐付',
                'song_info' => null,
            ];
        }

        if ($mapping->is_not_song) {
            return [
                'status' => 'not_song',
                'label' => '楽曲ではない',
                'song_info' => null,
            ];
        }

        if ($mapping->song) {
            $prefix = $mapping->is_manual ? '' : '[自動] ';
            $songInfo = $prefix.$mapping->song->title.' / '.$mapping->song->artist;

            return [
                'status' => $mapping->is_manual ? 'linked' : 'auto_linked',
                'label' => '紐付済',
                'song_info' => $songInfo,
            ];
        }

        return [
            'status' => 'unlinked',
            'label' => '未紐付',
            'song_info' => null,
        ];
    }
}
