<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class SongGroupReview extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'normalized_title',
        'song_ids_hash',
        'song_ids',
        'decision',
        'created_by',
    ];

    protected $casts = [
        'song_ids' => 'array',
    ];

    /**
     * 「別の曲」判定（今後候補として表示しない）
     */
    public const DECISION_DISTINCT = 'distinct';

    /**
     * 「保留」判定（一旦候補から外すが、後で見返せる）
     */
    public const DECISION_PENDING = 'pending';

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * 楽曲IDの組み合わせから一意なハッシュを生成する
     */
    public static function hashSongIds(array $songIds): string
    {
        $sorted = $songIds;
        sort($sorted);

        return sha1(implode(',', $sorted));
    }
}
