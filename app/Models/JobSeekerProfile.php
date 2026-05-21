<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class JobSeekerProfile extends Model
{
    protected $collection = 'job_seeker_profiles';

    protected $fillable = [
        'user_id',
        // Personal Information
        'full_name',
        'location',
        'age',
        'nationality',
        'gender',
        'marital_status',
        // Contact
        'phone',
        'address',
        // Career Information
        'years_of_experience',
        'job_level',
        'education_level',
        'current_job_status',
        'current_job_title',
        'experience_summary',
        'expected_salary',
        'is_actively_seeking',
        // Social & Portfolio
        'linkedin_url',
        'github_url',
        'portfolio_url',
        'twitter_url',
        // Structured data
        'skills',
        'education_history',
        'work_experience',
        // Resume / CV
        'resume',
        'cv_file_path',
        'ai_full_name',
        'ai_email',
        'ai_phone',
        'ai_location',
        'ai_summary',
        'ai_skills',
        'ai_work_history',
        'ai_projects',
        'ai_overall_evaluation',
        'ats_score',
        'ai_detected_language',
        'ai_analyzed_at',
    ];

    protected $casts = [
        'expected_salary' => 'float',
        'is_actively_seeking' => 'boolean',
        'age' => 'integer',
        'years_of_experience' => 'integer',
        'skills' => 'array',
        'education_history' => 'array',
        'work_experience' => 'array',
        'ai_skills' => 'array',
        'ai_work_history' => 'array',
        'ai_projects' => 'array',
        'ats_score' => 'integer',
        'ai_analyzed_at' => 'datetime',
    ];

    // Relationship with user
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}