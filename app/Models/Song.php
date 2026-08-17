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
        'match_key_title',
        'match_key_artist',
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
        // 保存時に正規化カラム・照合キーカラムを自動設定
        static::saving(function (Song $song) {
            if ($song->isDirty('title') || $song->normalized_title === null) {
                $song->normalized_title = TextNormalizer::normalize($song->title);
            }
            if ($song->isDirty('artist') || $song->normalized_artist === null) {
                $song->normalized_artist = TextNormalizer::normalize($song->artist);
            }
            if ($song->isDirty('title') || $song->match_key_title === null) {
                $song->match_key_title = TextNormalizer::matchKey($song->title);
            }
            if ($song->isDirty('artist') || $song->match_key_artist === null) {
                $song->match_key_artist = TextNormalizer::matchKey($song->artist);
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
