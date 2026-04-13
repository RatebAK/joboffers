<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class JobSeekerProfile extends Model
{
    protected $collection = 'job_seeker_profiles';

    protected $fillable = [
        'user_id',
        'phone',
        'address',
        'resume',
        'skills',
        'education_level',
        'experience_summary',
        'current_job_title',
        'expected_salary',
        'linkedin_url',
        'portfolio_url',
        'is_actively_seeking',
        'education_history',
        'work_experience',
    ];

    protected $casts = [
        'expected_salary' => 'float',
        'is_actively_seeking' => 'boolean',
        'skills' => 'array',
        'education_history' => 'array',
        'work_experience' => 'array',
    ];

    // Relationship with user
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}