<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChangeList extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'channel_id',
        'video_id',
        'comment_id',
        'is_display',
    ];

    public function archive()
    {
        return $this->belongsTo(Archive::class, 'video_id', 'video_id');
    }
    public function comment()
    {
        return $this->hasOne(TsItem::class, 'comment_id', 'comment_id');
    }
}
