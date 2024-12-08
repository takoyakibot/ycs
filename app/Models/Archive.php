<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Archive extends Model
{
    use HasFactory;

    protected $fillable = [
        'channel_id',
        'archive_id',
        'archive_name',
        'is_public',
        'is_display',
        'comments',
    ];

    protected $casts = [
        'comments' => 'array',
    ];
}
