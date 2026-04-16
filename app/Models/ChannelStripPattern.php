<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ChannelStripPattern extends Model
{
    use HasUlids;

    protected $fillable = [
        'channel_id',
        'pattern',
        'is_regex',
    ];

    protected $casts = [
        'is_regex' => 'boolean',
    ];

    public function channel()
    {
        return $this->belongsTo(Channel::class, 'channel_id', 'channel_id');
    }
}
