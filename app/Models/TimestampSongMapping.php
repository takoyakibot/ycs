<?php

namespace App\Models;

use App\Services\SimilarityService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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
     * モデルのブートメソッド
     */
    protected static function boot()
    {
        parent::boot();

        // 新規作成時にIDが未設定の場合、ULIDを自動生成
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = Str::ulid();
            }
        });
    }

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

    /**
     * 確定済みマッピング: 手動紐付け or レビュー承認済みの自動紐付け
     *
     * is_manual と status の複合条件。直接条件を書かず、このスコープを使うこと。
     */
    public function scopeConfirmed($query)
    {
        return $query->where('status', self::STATUS_LINKED)
            ->where('is_manual', true);
    }

    /**
     * 未レビューの自動紐付け: 自動紐付けされたがまだ確定されていない
     *
     * is_manual と status の複合条件。直接条件を書かず、このスコープを使うこと。
     */
    public function scopeAutoLinkedUnreviewed($query)
    {
        return $query->where('status', self::STATUS_LINKED)
            ->where('is_manual', false);
    }

    /**
     * このマッピングが確定済みかどうか
     */
    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_LINKED && $this->is_manual === true;
    }

    /**
     * このマッピングが未レビューの自動紐付けかどうか
     */
    public function isAutoLinkedUnreviewed(): bool
    {
        return $this->status === self::STATUS_LINKED && $this->is_manual === false;
    }

    /**
     * JOINクエリで「確定済み」を判定するための条件配列
     *
     * TimestampService 等の LEFT JOIN クエリではスコープが使えないため、
     * このメソッドで条件を一元管理する。
     */
    public static function confirmedJoinConditions(): array
    {
        return [
            'timestamp_song_mappings.status' => self::STATUS_LINKED,
            'timestamp_song_mappings.is_manual' => true,
        ];
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
