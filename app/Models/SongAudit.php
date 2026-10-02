<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class SongAudit extends Model
{
    use HasUlids;

    public const TARGET_SONG = 'song';

    public const TARGET_MAPPING = 'mapping';

    public const VERDICT_OK = 'ok';

    public const VERDICT_NEEDS_FIX = 'needs_fix';

    public const RESOLUTION_PENDING = 'pending';

    public const RESOLUTION_APPLIED = 'applied';

    public const RESOLUTION_REJECTED = 'rejected';

    protected $fillable = [
        'target_type',
        'target_id',
        'fingerprint',
        'verdict',
        'reason',
        'suggestion',
        'judged_by',
        'judged_at',
        'resolution',
    ];

    protected $casts = [
        'suggestion' => 'array',
        'judged_at' => 'datetime',
    ];
}
