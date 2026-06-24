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
        // Eager applicant profile fields
        'education',              // highest education level / degree
        'last_work',              // last job title / company
        'years_of_experience',    // integer
        'why_join',               // free text — motivation
        'what_to_add',            // free text — value proposition
        'positions_suited_for',   // array of position strings
        'notice_period',          // e.g. "2 weeks", "1 month", "immediately"
        'expected_salary',        // numeric or string e.g. "3000 USD"
    ];

    protected $casts = [
        'applied_at'           => 'datetime',
        'years_of_experience'  => 'integer',
        'positions_suited_for' => 'array',
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