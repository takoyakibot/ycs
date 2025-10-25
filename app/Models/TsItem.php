<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TsItem extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'archive_id',
        'type',
        'comment_id',
        'ts_text',
        'ts_num',
        'text',
        'song_id',
        'is_not_song',
    ];

    public function archive()
    {
        return $this->belongsTo(Archive::class, 'video_id', 'video_id');
    }

    public function changeList()
    {
        return $this->hasMany(ChangeList::class, 'comment_id', 'comment_id');
    }

    public function song()
    {
        return $this->belongsTo(Song::class);
    }
}
