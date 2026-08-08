<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class JobSeekerProfile extends Model
{
    protected $collection = 'job_seeker_profiles';

    protected $fillable = [
        'user_id',
        // Personal Information
        'first_name',
        'last_name',
        'full_name',
        'image',
        'gender',
        'nationality',
        'city',
        'location',
        'address',
        'phone',
        'date_of_birth',
        'marital_status',
        // Career Information
        'salary_range_from',
        'salary_range_to',
        'current_job_status',
        'years_of_experience',
        'education_level',
        'job_level',
        'job_types',       // array e.g. ['full-time', 'remote']
        'job_roles',       // array e.g. ['frontend', 'fullstack']
        'work_cities',     // array e.g. ['new-york', 'remote']
        'current_job_title',
        'experience_summary',
        'expected_salary',
        'is_actively_seeking',
        // Social Links
        'social_links',    // { linkedin, github, portfolio, twitter }
        // Structured data
        'skills',          // [{ id, name, level }]
        'education_history', // [{ id, certificate_type, university, faculty, major, major_name, grade, from_date, awarded_date }]
        'work_experience', // [{ id, job_title, company_name, job_roles, from_date, to_date, is_currently_working, description }]
        // Resume / CV
        'resume',          // Cloudinary URL of plain uploaded resume
        'resume_public_id', // Cloudinary public_id for deletion
        'cv_file_path',    // Cloudinary URL of AI-analyzed CV
        'cv_public_id',    // Cloudinary public_id for deletion
        // Profile image
        'image_public_id', // Cloudinary public_id for profile photo deletion
        // Resume file metadata
        'resume_file_type', // Original MIME type of uploaded resume (e.g. application/pdf)
        // Saved default cover letter (reused across applications)
        'default_cover_letter',
        // AI-derived fields
        'ai_full_name',
        'ai_email',
        'ai_phone',
        'ai_location',
        'ai_summary',
        'ai_skills',
        'ai_work_history',
        'ai_education_history',
        'ai_languages',
        'ai_projects',
        'ai_social_links',
        'ai_overall_evaluation',
        'ats_score',
        'ai_detected_language',
        'ai_analyzed_at',
        // Analysis status tracking
        'analysis_status', // pending, processing, completed, error
        'analysis_error',  // Error message if analysis failed
        'analysis_started_at', // When analysis started
        'analysis_completed_at', // When analysis completed/failed
    ];

    protected $attributes = [
        // Personal
        'first_name'       => null,
        'last_name'        => null,
        'full_name'        => null,
        'image'            => null,
        'image_public_id'  => null,
        'gender'           => null,
        'nationality'      => null,
        'city'             => null,
        'location'         => null,
        'address'          => null,
        'phone'            => null,
        'date_of_birth'    => null,
        'marital_status'   => null,
        // Career
        'current_job_title'   => null,
        'current_job_status'  => null,
        'job_level'           => null,
        'job_types'           => null,
        'job_roles'           => null,
        'work_cities'         => null,
        'years_of_experience' => null,
        'education_level'     => null,
        'expected_salary'     => null,
        'salary_range_from'   => null,
        'salary_range_to'     => null,
        'is_actively_seeking' => false,
        'experience_summary'  => null,
        'social_links'        => null,
        // Structured data
        'skills'              => null,
        'education_history'   => null,
        'work_experience'     => null,
        // Resume / CV
        'resume'              => null,
        'resume_public_id'    => null,
        'cv_file_path'        => null,
        'cv_public_id'        => null,
        'resume_file_type'    => null,
        'default_cover_letter'=> null,
        // AI-derived
        'ai_full_name'        => null,
        'ai_email'            => null,
        'ai_phone'            => null,
        'ai_location'         => null,
        'ai_summary'          => null,
        'ai_skills'           => null,
        'ai_work_history'     => null,
        'ai_education_history'=> null,
        'ai_languages'        => null,
        'ai_projects'         => null,
        'ai_social_links'     => null,
        'ai_overall_evaluation' => null,
        'ats_score'           => null,
        'ai_detected_language'=> null,
        'ai_analyzed_at'      => null,
        // Analysis status
        'analysis_status'     => null,
        'analysis_error'      => null,
        'analysis_started_at' => null,
        'analysis_completed_at' => null,
    ];

    protected $casts = [
        'expected_salary' => 'float',
        'salary_range_from' => 'float',
        'salary_range_to' => 'float',
        'is_actively_seeking' => 'boolean',
        'years_of_experience' => 'integer',
        'job_types' => 'array',
        'job_roles' => 'array',
        'work_cities' => 'array',
        'social_links' => 'array',
        'skills' => 'array',
        'education_history' => 'array',
        'work_experience' => 'array',
        'ai_skills' => 'array',
        'ai_work_history' => 'array',
        'ai_education_history' => 'array',
        'ai_languages' => 'array',
        'ai_projects' => 'array',
        'ai_social_links' => 'array',
        'ats_score' => 'integer',
        'ai_analyzed_at' => 'datetime',
        'analysis_started_at' => 'datetime',
        'analysis_completed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
