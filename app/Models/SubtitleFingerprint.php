<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SubtitleFingerprint extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'video_id',
        'ts_item_id',
        'start_sec',
        'duration_sec',
        'fingerprint_text',
        'trigrams',
    ];

    protected $casts = [
        'trigrams' => 'array',
        'start_sec' => 'integer',
        'duration_sec' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (SubtitleFingerprint $model) {
            if (empty($model->id)) {
                $model->id = Str::ulid();
            }
        });
    }

    public function archive()
    {
        return $this->belongsTo(Archive::class, 'video_id', 'video_id');
    }

    public function tsItem()
    {
        return $this->belongsTo(TsItem::class, 'ts_item_id', 'id');
    }
}
