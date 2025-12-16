<?php

namespace App\Models;

use App\Helpers\TextNormalizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Song extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'title',
        'artist',
        'spotify_track_id',
        'spotify_data',
        'youtube_url',
        'duration_ms',
        'normalized_title',
        'normalized_artist',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'spotify_data' => 'array',
    ];

    protected static function booted(): void
    {
        // 保存時に正規化カラムを自動設定
        static::saving(function (Song $song) {
            if ($song->isDirty('title') || $song->normalized_title === null) {
                $song->normalized_title = TextNormalizer::normalize($song->title);
            }
            if ($song->isDirty('artist') || $song->normalized_artist === null) {
                $song->normalized_artist = TextNormalizer::normalize($song->artist);
            }
        });
    }

    public function mappings()
    {
        return $this->hasMany(TimestampSongMapping::class, 'song_id', 'id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
