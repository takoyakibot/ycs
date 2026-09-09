<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SpectralScanData extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'spectral_scan_data';

    protected $fillable = [
        'id',
        'video_id',
        'sampling_interval',
        'duration',
        'spectral_data',
    ];

    protected $casts = [
        'spectral_data' => 'array',
        'duration' => 'float',
        'sampling_interval' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (SpectralScanData $model) {
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
