<?php

namespace App\Services;

use App\Models\NormalizationLog;
use App\Models\Song;
use App\Models\TimestampSongMapping;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SongMappingService
{
    /**
     * タイムスタンプと楽曲を紐づける（マッピングを作成）
     *
     * @param  string  $normalizedText  正規化済みテキスト
     * @param  string  $songId  楽曲ID
     * @param  int|null  $userId  操作者ID（nullの場合は現在のユーザー）
     */
    public function linkTimestamp(string $normalizedText, string $songId, ?int $userId = null): void
    {
        $userId = $userId ?? Auth::id();

        DB::transaction(function () use ($normalizedText, $songId, $userId) {
            $mapping = TimestampSongMapping::where('normalized_text', $normalizedText)->first();

            if ($mapping) {
                // 既存レコードを更新（IDは変更しない）
                // 保留状態からの紐付けの場合もstatus=linkedに戻す
                $mapping->update([
                    'song_id' => $songId,
                    'is_not_song' => false,
                    'status' => TimestampSongMapping::STATUS_LINKED,
                    'is_manual' => true,
                    'confidence' => 1.0,
                    'updated_by' => $userId,
                ]);
            } else {
                // 新規レコードを作成
                TimestampSongMapping::create([
                    'id' => Str::ulid(),
                    'normalized_text' => $normalizedText,
                    'song_id' => $songId,
                    'is_not_song' => false,
                    'status' => TimestampSongMapping::STATUS_LINKED,
                    'is_manual' => true,
                    'confidence' => 1.0,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);
            }

            // 操作ログを記録
            if ($userId) {
                NormalizationLog::log(
                    $userId,
                    NormalizationLog::ACTION_LINK,
                    NormalizationLog::TARGET_MAPPING,
                    $mapping->id ?? null,
                    [
                        'normalized_text' => $normalizedText,
                        'song_id' => $songId,
                    ]
                );
            }
        });
    }

    /**
     * タイムスタンプを「楽曲ではない」とマーク
     *
     * @param  string  $normalizedText  正規化済みテキスト
     * @param  int|null  $userId  操作者ID（nullの場合は現在のユーザー）
     */
    public function markAsNotSong(string $normalizedText, ?int $userId = null): void
    {
        $userId = $userId ?? Auth::id();

        DB::transaction(function () use ($normalizedText, $userId) {
            $mapping = TimestampSongMapping::where('normalized_text', $normalizedText)->first();

            if ($mapping) {
                // 既存レコードを更新（IDは変更しない）
                $mapping->update([
                    'song_id' => null,
                    'is_not_song' => true,
                    'is_manual' => true,
                    'confidence' => 1.0,
                    'updated_by' => $userId,
                ]);
            } else {
                // 新規レコードを作成
                $mapping = TimestampSongMapping::create([
                    'id' => Str::ulid(),
                    'normalized_text' => $normalizedText,
                    'song_id' => null,
                    'is_not_song' => true,
                    'is_manual' => true,
                    'confidence' => 1.0,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);
            }

            // 操作ログを記録
            if ($userId) {
                NormalizationLog::log(
                    $userId,
                    NormalizationLog::ACTION_MARK_NOT_SONG,
                    NormalizationLog::TARGET_MAPPING,
                    $mapping->id,
                    ['normalized_text' => $normalizedText]
                );
            }
        });
    }

    /**
     * 「楽曲ではない」フラグを解除
     *
     * @param  string  $normalizedText  正規化済みテキスト
     * @param  int|null  $userId  操作者ID（nullの場合は現在のユーザー）
     */
    public function unmarkAsNotSong(string $normalizedText, ?int $userId = null): void
    {
        $userId = $userId ?? Auth::id();

        DB::transaction(function () use ($normalizedText, $userId) {
            $mapping = TimestampSongMapping::where('normalized_text', $normalizedText)->first();

            if ($mapping && $mapping->is_not_song) {
                $mappingId = $mapping->id;

                // マッピングを削除して未紐づけ状態に戻す
                $mapping->delete();

                // 操作ログを記録
                if ($userId) {
                    NormalizationLog::log(
                        $userId,
                        NormalizationLog::ACTION_UNLINK,
                        NormalizationLog::TARGET_MAPPING,
                        $mappingId,
                        ['normalized_text' => $normalizedText, 'was_not_song' => true]
                    );
                }
            }
        });
    }

    /**
     * マッピングを解除
     *
     * @param  string  $normalizedText  正規化済みテキスト
     * @param  int|null  $userId  操作者ID（nullの場合は現在のユーザー）
     */
    public function unlinkTimestamp(string $normalizedText, ?int $userId = null): void
    {
        $userId = $userId ?? Auth::id();

        $mapping = TimestampSongMapping::where('normalized_text', $normalizedText)->first();

        if ($mapping) {
            $mappingId = $mapping->id;
            $songId = $mapping->song_id;

            $mapping->delete();

            // 操作ログを記録
            if ($userId) {
                NormalizationLog::log(
                    $userId,
                    NormalizationLog::ACTION_UNLINK,
                    NormalizationLog::TARGET_MAPPING,
                    $mappingId,
                    ['normalized_text' => $normalizedText, 'song_id' => $songId]
                );
            }
        }
    }

    /**
     * 指定した楽曲IDに紐づくすべてのマッピングを削除
     *
     * @param  string  $songId  楽曲ID
     * @param  int|null  $userId  操作者ID（nullの場合は現在のユーザー）
     */
    public function deleteMappingsBySongId(string $songId, ?int $userId = null): void
    {
        $userId = $userId ?? Auth::id();

        $mappings = TimestampSongMapping::where('song_id', $songId)->get();

        foreach ($mappings as $mapping) {
            $mapping->delete();

            // 操作ログを記録
            if ($userId) {
                NormalizationLog::log(
                    $userId,
                    NormalizationLog::ACTION_UNLINK,
                    NormalizationLog::TARGET_MAPPING,
                    $mapping->id,
                    ['normalized_text' => $mapping->normalized_text, 'song_id' => $songId, 'bulk_delete' => true]
                );
            }
        }
    }

    /**
     * 自動紐付けを確定（手動紐付けに変更）
     *
     * @param  string  $normalizedText  正規化済みテキスト
     * @param  int|null  $userId  操作者ID（nullの場合は現在のユーザー）
     * @return bool 確定成功した場合true
     */
    public function confirmAutoLink(string $normalizedText, ?int $userId = null): bool
    {
        $userId = $userId ?? Auth::id();

        $mapping = TimestampSongMapping::where('normalized_text', $normalizedText)
            ->where('is_manual', false)
            ->whereNotNull('song_id')
            ->first();

        if (! $mapping) {
            return false;
        }

        $mapping->update([
            'is_manual' => true,
            'confidence' => 1.0,
            'updated_by' => $userId,
        ]);

        // 操作ログを記録
        if ($userId) {
            NormalizationLog::log(
                $userId,
                NormalizationLog::ACTION_CONFIRM_AUTO_LINK,
                NormalizationLog::TARGET_MAPPING,
                $mapping->id,
                ['normalized_text' => $normalizedText, 'song_id' => $mapping->song_id]
            );
        }

        return true;
    }

    /**
     * タイムスタンプを「保留」状態にする
     * 自動紐付けを解除し、再び自動紐付けの対象にならないようにする
     *
     * @param  string  $normalizedText  正規化済みテキスト
     * @param  int|null  $userId  操作者ID（nullの場合は現在のユーザー）
     * @return bool 保留成功した場合true
     */
    public function markAsPending(string $normalizedText, ?int $userId = null): bool
    {
        $userId = $userId ?? Auth::id();

        return DB::transaction(function () use ($normalizedText, $userId) {
            $mapping = TimestampSongMapping::where('normalized_text', $normalizedText)->first();

            $previousSongId = null;
            $previousSong = null;

            if ($mapping) {
                // 既存レコードの場合は保留状態に更新
                $previousSongId = $mapping->song_id;
                if ($previousSongId) {
                    $previousSong = Song::find($previousSongId);
                }

                $mapping->update([
                    'song_id' => null,
                    'is_not_song' => false,
                    'status' => TimestampSongMapping::STATUS_PENDING,
                    'is_manual' => true, // 手動操作として扱う
                    'confidence' => null,
                    'updated_by' => $userId,
                ]);
            } else {
                // 新規レコードを作成（保留状態で）
                $mapping = TimestampSongMapping::create([
                    'id' => Str::ulid(),
                    'normalized_text' => $normalizedText,
                    'song_id' => null,
                    'is_not_song' => false,
                    'status' => TimestampSongMapping::STATUS_PENDING,
                    'is_manual' => true,
                    'confidence' => null,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);
            }

            // 操作ログを記録
            if ($userId) {
                $details = ['normalized_text' => $normalizedText];
                if ($previousSongId) {
                    $details['previous_song_id'] = $previousSongId;
                }
                if ($previousSong) {
                    $details['previous_song_title'] = $previousSong->title;
                    $details['previous_song_artist'] = $previousSong->artist;
                }

                NormalizationLog::log(
                    $userId,
                    NormalizationLog::ACTION_MARK_PENDING,
                    NormalizationLog::TARGET_MAPPING,
                    $mapping->id,
                    $details
                );
            }

            return true;
        });
    }
}
