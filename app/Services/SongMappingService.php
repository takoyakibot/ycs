<?php

namespace App\Services;

use App\Models\NormalizationLog;
use App\Models\Song;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
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
                    'confidence' => 1.0,
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
                    'confidence' => 1.0,
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

    /**
     * 特定のタイムスタンプに個別で楽曲を紐づける
     *
     * @param  string  $tsItemId  タイムスタンプID
     * @param  string  $songId  楽曲ID
     * @param  int|null  $userId  操作者ID（nullの場合は現在のユーザー）
     */
    public function linkTsItemToSong(string $tsItemId, string $songId, ?int $userId = null): void
    {
        $userId = $userId ?? Auth::id();

        DB::transaction(function () use ($tsItemId, $songId, $userId) {
            $tsItem = TsItem::findOrFail($tsItemId);
            $tsItem->update(['song_id' => $songId]);

            // 操作ログを記録
            if ($userId) {
                NormalizationLog::log(
                    $userId,
                    NormalizationLog::ACTION_LINK,
                    NormalizationLog::TARGET_TS_ITEM,
                    $tsItemId,
                    [
                        'normalized_text' => $tsItem->normalized_text,
                        'song_id' => $songId,
                        'individual' => true,
                    ]
                );
            }
        });
    }

    /**
     * 特定のタイムスタンプの個別マッピングを解除
     *
     * @param  string  $tsItemId  タイムスタンプID
     * @param  int|null  $userId  操作者ID（nullの場合は現在のユーザー）
     */
    public function unlinkTsItem(string $tsItemId, ?int $userId = null): void
    {
        $userId = $userId ?? Auth::id();

        $tsItem = TsItem::findOrFail($tsItemId);

        if ($tsItem->song_id) {
            $oldSongId = $tsItem->song_id;
            $tsItem->update(['song_id' => null]);

            // 操作ログを記録
            if ($userId) {
                NormalizationLog::log(
                    $userId,
                    NormalizationLog::ACTION_UNLINK,
                    NormalizationLog::TARGET_TS_ITEM,
                    $tsItemId,
                    [
                        'normalized_text' => $tsItem->normalized_text,
                        'song_id' => $oldSongId,
                        'individual' => true,
                    ]
                );
            }
        }
    }

    /**
     * 同じnormalized_textを持つタイムスタンプの数を取得
     *
     * @param  string  $normalizedText  正規化済みテキスト
     * @return int タイムスタンプの数
     */
    public function countTsItemsByNormalizedText(string $normalizedText): int
    {
        return TsItem::where('normalized_text', $normalizedText)->count();
    }

    /**
     * 同じnormalized_textを持つタイムスタンプの情報を取得
     *
     * @param  string  $normalizedText  正規化済みテキスト
     * @return array タイムスタンプ情報の配列
     */
    public function getTsItemsByNormalizedText(string $normalizedText): array
    {
        return TsItem::where('normalized_text', $normalizedText)
            ->with(['archive', 'song'])
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'text' => $item->text,
                    'normalized_text' => $item->normalized_text,
                    'video_id' => $item->video_id,
                    'ts_text' => $item->ts_text,
                    'song_id' => $item->song_id,
                    'song' => $item->song,
                    'archive_title' => $item->archive?->title,
                    'archive_channel_id' => $item->archive?->channel_id,
                ];
            })
            ->toArray();
    }
}
