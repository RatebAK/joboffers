<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class CoachMessage extends Model
{
    protected $collection = 'coach_messages';

    protected $fillable = [
        'session_id',
        'role',    // 'user' | 'assistant'
        'content',
    ];

    public function session()
    {
        return $this->belongsTo(CoachSession::class, 'session_id');
    }
}
