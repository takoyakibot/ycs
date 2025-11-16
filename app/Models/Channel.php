<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Channel extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['handle', 'channel_id', 'title', 'thumbnail', 'user_id'];

    protected $hidden = ['channel_id'];

    public function archives()
    {
        return $this->hasMany(Archive::class, 'channel_id', 'channel_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
