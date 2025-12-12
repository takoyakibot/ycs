<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TimestampReport extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'video_id',
        'ts_text',
        'ts_num',
        'report_type',
        'comment',
        'status',
        'reporter_ip',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'ts_num' => 'integer',
    ];

    /**
     * 対応するts_itemを取得（複合キーでの検索）
     * belongsToリレーションは複合キーに対応していないため、アクセサで実装
     */
    public function getTsItemAttribute()
    {
        return TsItem::where('video_id', $this->video_id)
            ->where('ts_text', $this->ts_text)
            ->where('ts_num', $this->ts_num)
            ->first();
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
