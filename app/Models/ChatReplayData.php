<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ChatReplayData extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'chat_replay_data';

    protected $fillable = [
        'id',
        'video_id',
        'duration',
        'message_count',
        'chat_data',
    ];

    protected $casts = [
        'chat_data' => 'array',
        'duration' => 'float',
        'message_count' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (ChatReplayData $model) {
            if (empty($model->id)) {
                $model->id = Str::ulid();
            }
        });
    }

    public function archive()
    {
        return $this->belongsTo(Archive::class, 'video_id', 'video_id');
    }
}
