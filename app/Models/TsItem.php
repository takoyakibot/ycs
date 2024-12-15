<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TsItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'archive_id',
        'type',
        'ts_text',
        'ts_num',
        'text',
    ];
}
