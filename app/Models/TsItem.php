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
        'video_id',
        'type',
        'ts_text',
        'ts_num',
        'text',
        'comment_id',
        'is_display',
    ];

    public function archive()
    {
        return $this->belongsTo(Archive::class, 'video_id', 'video_id');
    }

    public function changeList()
    {
        return $this->hasMany(ChangeList::class, 'comment_id', 'comment_id');
    }

    /**
     * textフィールドを取得する際に先頭の全角スペースを除外
     */
    public function getTextAttribute($value)
    {
        return \App\Helpers\TextNormalizer::trimFullwidthSpace($value);
    }

    /**
     * タイムスタンプテキストを正規化して取得
     */
    public function getNormalizedTextAttribute()
    {
        return \App\Helpers\TextNormalizer::normalize($this->text);
    }
}
