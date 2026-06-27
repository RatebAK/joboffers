<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class CoachSession extends Model
{
    protected $collection = 'coach_sessions';

    protected $fillable = [
        'user_id',
        'title',
    ];

    public function messages()
    {
        return $this->hasMany(CoachMessage::class, 'session_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
