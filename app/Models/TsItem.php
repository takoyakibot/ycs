<?php

namespace App\Models;

use App\Helpers\TextNormalizer;
use App\Services\TimestampExtractorService;
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
        'song_id',
        'comment_id',
        'is_display',
    ];

    protected static function booted(): void
    {
        static::saving(function (TsItem $tsItem) {
            // textが変更された場合、または normalized_text が未設定/nullの場合に正規化
            $normalizedTextIsNull = ! array_key_exists('normalized_text', $tsItem->attributes)
                || $tsItem->attributes['normalized_text'] === null;

            if ($tsItem->isDirty('text') || $normalizedTextIsNull) {
                // アクセサを経由せず生のtext値を取得して正規化
                $rawText = $tsItem->attributes['text'] ?? null;

                // チャンネルの除去パターンを適用
                $textForNormalize = $rawText;
                if ($rawText !== null && $tsItem->video_id) {
                    $stripPatterns = self::getStripPatternsForVideo($tsItem->video_id);
                    if (! empty($stripPatterns)) {
                        $textForNormalize = TimestampExtractorService::applyStripPatterns($rawText, $stripPatterns);
                    }
                }

                $normalized = TextNormalizer::normalize($textForNormalize);

                // 正規化結果が空の場合（例: "-"のみの場合）は除去パターン適用後のテキストを小文字化して使用
                // これにより timestamp_song_mappings との JOIN が正しく動作する
                if ($normalized === '' && $textForNormalize !== null && trim($textForNormalize) !== '') {
                    $normalized = mb_strtolower(trim($textForNormalize), 'UTF-8');
                }

                // 正規化結果が現在の値と異なる場合のみ更新（無限ループ回避）
                if (($tsItem->attributes['normalized_text'] ?? null) !== $normalized) {
                    $tsItem->attributes['normalized_text'] = $normalized;
                }
            }
        });
    }

    /**
     * video_idからチャンネルの除去パターンを取得（メモリキャッシュ付き）
     */
    private static array $stripPatternsCache = [];

    private static function getStripPatternsForVideo(string $videoId): array
    {
        if (array_key_exists($videoId, self::$stripPatternsCache)) {
            return self::$stripPatternsCache[$videoId];
        }

        $archive = Archive::where('video_id', $videoId)->first();
        if (! $archive) {
            self::$stripPatternsCache[$videoId] = [];

            return [];
        }

        $channel = Channel::where('channel_id', $archive->channel_id)->first();
        if (! $channel) {
            self::$stripPatternsCache[$videoId] = [];

            return [];
        }

        $patterns = $channel->stripPatterns()->pluck('pattern')->toArray();
        self::$stripPatternsCache[$videoId] = $patterns;

        return $patterns;
    }

    public function archive()
    {
        return $this->belongsTo(Archive::class, 'video_id', 'video_id');
    }

    public function song()
    {
        return $this->belongsTo(Song::class, 'song_id', 'id');
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
        if ($value !== null) {
            return $value;
        }

        // カラムに値がなければ動的に計算（生の値を使用）
        $rawText = $this->attributes['text'] ?? null;
        $normalized = TextNormalizer::normalize($rawText);

        // 正規化結果が空の場合は元テキストを小文字化して返す
        if ($normalized === '' && $rawText !== null && trim($rawText) !== '') {
            return mb_strtolower(trim($rawText), 'UTF-8');
        }

        return $normalized;
    }
}
