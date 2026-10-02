<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TimestampDecomposition extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'normalized_text',
        'original_text',
        'parts',
        'separator_count',
        'title_part_index',
        'artist_part_index',
        'derived_title',
        'derived_artist',
        'status',
        'confidence',
        'cascade_group_id',
        'song_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'parts' => 'array',
        'separator_count' => 'integer',
        'title_part_index' => 'integer',
        'artist_part_index' => 'integer',
        'confidence' => 'float',
    ];

    /**
     * ステータス定数
     */
    public const STATUS_PENDING = 'pending';

    public const STATUS_SELECTED = 'selected';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_AUTO_MATCHED = 'auto_matched';

    /**
     * 紐付け先の楽曲
     */
    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class, 'song_id');
    }

    /**
     * 現在のマッピング（正規化画面での紐付け）
     */
    public function currentMapping(): HasOne
    {
        return $this->hasOne(TimestampSongMapping::class, 'normalized_text', 'normalized_text');
    }

    /**
     * 作成者
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * 更新者
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * 判定時と現在のマッピングが食い違っているか
     *
     * SQL側の changed フィルタ（TimestampDecompositionService::getAutoMatchedList）と
     * 同じ仕様を PHP で判定する。片方を変更したら他方も合わせること。
     */
    public function isMappingChanged(): bool
    {
        $mapping = $this->currentMapping;

        if ($mapping === null) {
            return $this->song_id !== null;
        }

        if ($mapping->is_not_song) {
            return true;
        }

        return ($mapping->song_id ?? '') !== ($this->song_id ?? '');
    }

    /**
     * 未処理のレコードを取得するスコープ
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * 選別済みのレコードを取得するスコープ
     */
    public function scopeSelected($query)
    {
        return $query->where('status', self::STATUS_SELECTED);
    }

    /**
     * 分解されたパーツを持つ（2つ以上のパーツ）レコードを取得するスコープ
     */
    public function scopeHasMultipleParts($query)
    {
        return $query->where('separator_count', '>', 0);
    }
}
