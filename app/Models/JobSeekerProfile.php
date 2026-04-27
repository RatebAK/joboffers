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