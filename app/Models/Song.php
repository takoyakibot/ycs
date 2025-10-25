<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Song extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'artist',
        'spotify_id',
        'spotify_uri',
        'spotify_preview_url',
        'spotify_data',
    ];

    protected $casts = [
        'spotify_data' => 'array',
    ];

    /**
     * タイムスタンプアイテムとのリレーション
     */
    public function tsItems()
    {
        return $this->hasMany(TsItem::class);
    }
}
