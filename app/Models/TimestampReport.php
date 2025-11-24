<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimestampReport extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'ts_item_id',
        'video_id',
        'report_type',
        'comment',
        'status',
        'reporter_ip',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    /**
     * 報告対象のタイムスタンプ
     */
    public function tsItem(): BelongsTo
    {
        return $this->belongsTo(TsItem::class, 'ts_item_id');
    }

    /**
     * 報告を解決済みにする
     */
    public function markAsResolved(): void
    {
        $this->update([
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);
    }

    /**
     * 未解決の報告をスコープで取得
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * 解決済みの報告をスコープで取得
     */
    public function scopeResolved($query)
    {
        return $query->where('status', 'resolved');
    }
}
