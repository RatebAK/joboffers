<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Application extends Model
{
    protected $collection = 'applications';

    protected $fillable = [
        'user_id',
        'job_post_id',
        'cover_letter',
        'resume',
        'status',
        'feedback',
        'applied_at',
    ];

    protected $casts = [
        'applied_at' => 'datetime',
    ];

    // Relationship with user (job seeker)
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Relationship with job post
    public function jobPost()
    {
        return $this->belongsTo(JobPost::class);
    }

    // Scope for pending applications
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    // Scope for user applications
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}