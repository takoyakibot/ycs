<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Archive extends Model
{
    use HasFactory;

    protected $fillable = [
        'channel_id',
        'video_id',
        'title',
        'thumbnail',
        'is_public',
        'is_display',
        'comments',
        'last_updated',
    ];

    protected $hidden = ['channel_id'];

    protected $casts = [
        'comments' => 'array',
    ];
}
