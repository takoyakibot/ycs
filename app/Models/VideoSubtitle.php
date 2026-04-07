<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class VideoSubtitle extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'video_id',
        'language_code',
        'kind',
        'subtitle_data',
        'segment_count',
    ];

    protected $casts = [
        'subtitle_data' => 'array',
        'segment_count' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (VideoSubtitle $model) {
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
