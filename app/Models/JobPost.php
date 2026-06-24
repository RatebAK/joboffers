<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class JobPost extends Model
{
    protected $collection = 'job_posts';

    // Communication method enum
    const COMM_BY_PHONE   = 'by_phone';
    const COMM_BY_FORSA   = 'by_forsa';
    const COMM_BY_WEBSITE = 'by_website';

    protected $fillable = [
        'job_id',              // human-readable display ID e.g. "JOB-001"
        'employer_id',
        // Company info — auto-populated from CompanyProfile, never user-supplied
        'company_profile_id',
        'company_name',        // denormalised from CompanyProfile.name (immutable)
        'company_logo',        // denormalised from CompanyProfile.logo

        // How applicants should send their CV
        'communication_method', // by_phone | by_forsa | by_website
        'communication_value',  // phone number, or website URL (null for by_forsa)

        // Employee specification
        'title',               // job title
        'roles',               // array of role/category strings
        'portfolio_required',  // bool
        'cover_letter_required', // bool
        'gender',              // male | female | no_preference
        'age_from',            // int|null
        'age_to',              // int|null
        'education_level',     // high_school | diploma | bachelor | master | phd | any
        'job_level',           // entry | junior | mid | senior | manager | director
        'experience_years',    // int — years required
        'languages',           // array of language strings

        // Work details
        'vacancies',           // int — number of open slots
        'job_type',            // full_time | part_time | contract | freelance
        'work_mode',           // remote | hybrid | on_site
        'city',                // job city
        'address',             // full address
        'salary_from',         // int
        'salary_to',           // int
        'currency',            // USD | SYP | EUR | etc.
        'display_salary',      // bool — show salary on listing
        'incentives',          // free text — commissions, bonuses, insurance

        // Job vacancy information
        'description',         // rich text — summary & responsibilities
        'requirements',        // rich text — skills & expertise
        'questions',           // array of { question: string, required: bool }

        // Status / meta
        'category',
        'tags',                // array of searchable tags
        'is_active',
        'expires_at',          // datetime|null
    ];

    protected $casts = [
        'is_active'              => 'boolean',
        'portfolio_required'     => 'boolean',
        'cover_letter_required'  => 'boolean',
        'display_salary'         => 'boolean',
        'age_from'               => 'integer',
        'age_to'                 => 'integer',
        'experience_years'       => 'integer',
        'vacancies'              => 'integer',
        'salary_from'            => 'integer',
        'salary_to'              => 'integer',
        'roles'                  => 'array',
        'languages'              => 'array',
        'tags'                   => 'array',
        'questions'              => 'array',
        'expires_at'             => 'datetime',
    ];

    public function employer()
    {
        return $this->belongsTo(User::class, 'employer_id');
    }

    public function companyProfile()
    {
        return $this->belongsTo(CompanyProfile::class, 'company_profile_id');
    }

    public function applications()
    {
        return $this->hasMany(Application::class);
    }
}
