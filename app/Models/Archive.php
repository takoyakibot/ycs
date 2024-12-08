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
        'is_public',
        'is_display',
        'comments',
    ];

    protected $hidden = ['channel_id'];

    protected $casts = [
        'comments' => 'array',
    ];
}
