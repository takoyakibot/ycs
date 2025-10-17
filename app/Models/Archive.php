<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Archive extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
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

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public function tsItems()
    {
        return $this->hasMany(TsItem::class, 'video_id', 'video_id')
            ->orderBy('type', 'asc')
            ->orderBy('comment_id', 'asc')
            ->orderBy('ts_num', 'asc');
    }

    public function tsItemsDisplay()
    {
        return $this->hasMany(TsItem::class, 'video_id', 'video_id')
            ->where('is_display', '1')
            ->orderBy('ts_num', 'asc');
    }

    public function channel()
    {
        return $this->belongsTo(Channel::class, 'channel_id', 'channel_id');
    }

    public function changeList()
    {
        return $this->hasMany(ChangeList::class, 'video_id', 'video_id');
    }
}
