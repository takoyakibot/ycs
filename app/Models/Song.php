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
        'video_url',
        'duration_ms',
        'normalized_title',
        'normalized_artist',
        'review_status',
        'created_by',
        'updated_by',
    ];

    public const REVIEW_STATUS_SAFE = 'safe';

    public const REVIEW_STATUS_NEEDS_REVIEW = 'needs_review';

    protected $casts = [
        'spotify_data' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (Song $song) {
            if ($song->isDirty('title') || $song->normalized_title === null) {
                $song->normalized_title = TextNormalizer::normalize($song->title);
            }
            if ($song->isDirty('artist') || $song->normalized_artist === null) {
                $song->normalized_artist = TextNormalizer::normalize($song->artist);
            }

            if ($song->exists && ($song->isDirty('title') || $song->isDirty('artist')) && ! $song->isDirty('review_status')) {
                $song->review_status = null;
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

    public function tags()
    {
        return $this->hasMany(SongTag::class);
    }
}
