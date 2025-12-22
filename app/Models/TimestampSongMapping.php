<?php

namespace App\Models;

use App\Services\SimilarityService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TimestampSongMapping extends Model
{
    use HasFactory;

    /**
     * ステータス定数
     */
    public const STATUS_LINKED = 'linked';   // 紐付け済み

    public const STATUS_PENDING = 'pending'; // 保留（自動紐付けの対象外）

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'normalized_text',
        'song_id',
        'is_not_song',
        'status',
        'is_manual',
        'confidence',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_not_song' => 'boolean',
        'is_manual' => 'boolean',
        'confidence' => 'float',
    ];

    /**
     * 保留状態かどうかを判定
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * 紐付け済み状態かどうかを判定
     */
    public function isLinked(): bool
    {
        return $this->status === self::STATUS_LINKED;
    }

    public function song()
    {
        return $this->belongsTo(Song::class, 'song_id', 'id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * タイムスタンプテキストからマッピングを検索（完全一致）
     */
    public static function findByText($text)
    {
        $normalized = \App\Helpers\TextNormalizer::normalize($text);

        return static::where('normalized_text', $normalized)->first();
    }

    /**
     * あいまい検索でマッピングを検索
     */
    public static function fuzzySearch($text, $threshold = 0.7)
    {
        $normalized = \App\Helpers\TextNormalizer::normalize($text);

        // まず完全一致を試す
        $exact = static::where('normalized_text', $normalized)->first();
        if ($exact) {
            return $exact;
        }

        // 部分一致とLike検索
        $candidates = static::where('normalized_text', 'like', "%{$normalized}%")
            ->orWhere('normalized_text', 'like', substr($normalized, 0, 20).'%')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        // 類似度計算
        $best = null;
        $bestScore = 0;
        $similarityService = app(SimilarityService::class);

        foreach ($candidates as $candidate) {
            $similarity = $similarityService->calculateSimilarity($normalized, $candidate->normalized_text);
            if ($similarity > $bestScore && $similarity >= $threshold) {
                $bestScore = $similarity;
                $best = $candidate;
            }
        }

        return $best;
    }
}
