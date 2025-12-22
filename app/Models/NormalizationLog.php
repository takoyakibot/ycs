<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class NormalizationLog extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'id',
        'user_id',
        'action',
        'target_type',
        'target_id',
        'details',
        'created_at',
    ];

    protected $casts = [
        'details' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * アクション種別
     */
    public const ACTION_LINK = 'link';

    public const ACTION_UNLINK = 'unlink';

    public const ACTION_CREATE_SONG = 'create_song';

    public const ACTION_DELETE_SONG = 'delete_song';

    public const ACTION_MARK_NOT_SONG = 'mark_not_song';

    public const ACTION_CONFIRM_AUTO_LINK = 'confirm_auto_link';

    public const ACTION_MARK_PENDING = 'mark_pending';

    /**
     * ターゲット種別
     */
    public const TARGET_MAPPING = 'timestamp_song_mapping';

    public const TARGET_SONG = 'song';

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * ログを記録
     */
    public static function log(
        int $userId,
        string $action,
        string $targetType,
        ?string $targetId = null,
        ?array $details = null
    ): self {
        return static::create([
            'user_id' => $userId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'details' => $details,
        ]);
    }
}
