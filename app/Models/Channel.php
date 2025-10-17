<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Channel extends Model
{
    use HasFactory;

    protected $fillable = ['handle', 'channel_id', 'title', 'thumbnail'];

    protected $hidden = ['channel_id'];

    public function archives()
    {
        return $this->hasMany(Archive::class, 'channel_id', 'channel_id');
    }
}
