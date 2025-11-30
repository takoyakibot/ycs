<?php

namespace App\Models;

use App\Helpers\TextNormalizer;
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
        'normalized_text',
        'comment_id',
        'is_display',
    ];

    protected static function booted(): void
    {
        static::saving(function (TsItem $tsItem) {
            // textが変更された場合、または normalized_text が null の場合に正規化
            if ($tsItem->isDirty('text') || $tsItem->attributes['normalized_text'] === null) {
                // アクセサを経由せず生のtext値を取得して正規化
                $rawText = $tsItem->attributes['text'] ?? null;
                $tsItem->normalized_text = TextNormalizer::normalize($rawText);
            }
        });
    }

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
        return TextNormalizer::trimFullwidthSpace($value);
    }

    /**
     * 正規化テキストを取得（カラムがnullの場合は動的計算）
     */
    public function getNormalizedTextAttribute($value)
    {
        // カラムに値があればそれを返す、なければ動的に計算
        return $value ?? TextNormalizer::normalize($this->text);
    }
}
