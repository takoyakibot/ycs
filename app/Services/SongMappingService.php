<?php

namespace App\Services;

use App\Models\TimestampSongMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SongMappingService
{
    /**
     * タイムスタンプと楽曲を紐づける（マッピングを作成）
     *
     * @param  string  $normalizedText  正規化済みテキスト
     * @param  string  $songId  楽曲ID
     */
    public function linkTimestamp(string $normalizedText, string $songId): void
    {
        DB::transaction(function () use ($normalizedText, $songId) {
            $mapping = TimestampSongMapping::where('normalized_text', $normalizedText)->first();

            if ($mapping) {
                // 既存レコードを更新（IDは変更しない）
                $mapping->update([
                    'song_id' => $songId,
                    'is_not_song' => false,
                    'is_manual' => true,
                    'confidence' => 1.0,
                ]);
            } else {
                // 新規レコードを作成
                TimestampSongMapping::create([
                    'id' => Str::ulid(),
                    'normalized_text' => $normalizedText,
                    'song_id' => $songId,
                    'is_not_song' => false,
                    'is_manual' => true,
                    'confidence' => 1.0,
                ]);
            }
        });
    }

    /**
     * タイムスタンプを「楽曲ではない」とマーク
     *
     * @param  string  $normalizedText  正規化済みテキスト
     */
    public function markAsNotSong(string $normalizedText): void
    {
        DB::transaction(function () use ($normalizedText) {
            $mapping = TimestampSongMapping::where('normalized_text', $normalizedText)->first();

            if ($mapping) {
                // 既存レコードを更新（IDは変更しない）
                $mapping->update([
                    'song_id' => null,
                    'is_not_song' => true,
                    'is_manual' => true,
                    'confidence' => 1.0,
                ]);
            } else {
                // 新規レコードを作成
                TimestampSongMapping::create([
                    'id' => Str::ulid(),
                    'normalized_text' => $normalizedText,
                    'song_id' => null,
                    'is_not_song' => true,
                    'is_manual' => true,
                    'confidence' => 1.0,
                ]);
            }
        });
    }

    /**
     * 「楽曲ではない」フラグを解除
     *
     * @param  string  $normalizedText  正規化済みテキスト
     */
    public function unmarkAsNotSong(string $normalizedText): void
    {
        DB::transaction(function () use ($normalizedText) {
            $mapping = TimestampSongMapping::where('normalized_text', $normalizedText)->first();

            if ($mapping && $mapping->is_not_song) {
                // マッピングを削除して未紐づけ状態に戻す
                $mapping->delete();
            }
        });
    }

    /**
     * マッピングを解除
     *
     * @param  string  $normalizedText  正規化済みテキスト
     */
    public function unlinkTimestamp(string $normalizedText): void
    {
        TimestampSongMapping::where('normalized_text', $normalizedText)->delete();
    }

    /**
     * 指定した楽曲IDに紐づくすべてのマッピングを削除
     *
     * @param  string  $songId  楽曲ID
     */
    public function deleteMappingsBySongId(string $songId): void
    {
        TimestampSongMapping::where('song_id', $songId)->delete();
    }
}
