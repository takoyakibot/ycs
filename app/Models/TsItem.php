<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TsItem extends Model
{
    use HasFactory;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'archive_id',
        'type',
        'ts_text',
        'ts_num',
        'text',
    ];

    public function archive()
    {
        return $this->belongsTo(Archive::class, 'video_id', 'video_id');
    }
}
