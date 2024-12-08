<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Channel extends Model
{
    use HasFactory;

    protected $fillable = ['handle', 'channel_id', 'name', 'thumbnail'];

    protected $hidden = ['channel_id'];
}
