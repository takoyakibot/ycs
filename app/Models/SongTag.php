<?php

namespace App\Models;

use App\Helpers\TextNormalizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SongTag extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'song_id',
        'value',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = Str::ulid();
            }
        });

        static::saving(function ($model) {
            if ($model->isDirty('value') || $model->normalized_value === null) {
                $model->normalized_value = TextNormalizer::normalize($model->value);
            }
        });
    }

    public function song()
    {
        return $this->belongsTo(Song::class);
    }
}
