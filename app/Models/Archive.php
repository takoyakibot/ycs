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
        'published_at',
        'comments_updated_at',
    ];

    protected $hidden = ['channel_id'];

    protected $primaryKey = 'video_id';
    public $incrementing = false;
    protected $keyType = 'string';

    public function tsItems()
    {
        return $this->hasMany(TsItem::class, 'video_id', 'video_id')
            ->orderBy('ts_num', 'asc');
    }
}
