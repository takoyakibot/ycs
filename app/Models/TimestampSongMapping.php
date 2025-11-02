<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TimestampSongMapping extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'normalized_text',
        'song_id',
        'is_not_song',
        'is_manual',
        'confidence',
    ];

    protected $casts = [
        'is_not_song' => 'boolean',
        'is_manual' => 'boolean',
        'confidence' => 'float',
    ];

    public function song()
    {
        return $this->belongsTo(Song::class, 'song_id', 'id');
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
            ->orWhere('normalized_text', 'like', substr($normalized, 0, 20) . '%')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        // 類似度計算
        $best = null;
        $bestScore = 0;

        foreach ($candidates as $candidate) {
            $similarity = static::calculateSimilarity($normalized, $candidate->normalized_text);
            if ($similarity > $bestScore && $similarity >= $threshold) {
                $bestScore = $similarity;
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * 2つのテキストの類似度を計算（0.0〜1.0）
     */
    private static function calculateSimilarity($str1, $str2)
    {
        // Levenshtein距離ベースの類似度
        $len1 = mb_strlen($str1);
        $len2 = mb_strlen($str2);

        if ($len1 === 0 || $len2 === 0) {
            return 0.0;
        }

        $distance = levenshtein($str1, $str2);
        $maxLen = max($len1, $len2);

        return 1.0 - ($distance / $maxLen);
    }
}
