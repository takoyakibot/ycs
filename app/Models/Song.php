<?php

namespace App\Models;

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
    ];

    protected $casts = [
        'spotify_data' => 'array',
    ];

    public function mappings()
    {
        return $this->hasMany(TimestampSongMapping::class, 'song_id', 'id');
    }
}
