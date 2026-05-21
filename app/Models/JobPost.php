<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class JobPost extends Model
{
    protected $collection = 'job_posts';

    protected $fillable = [
        'job_id',
        'title',
        'description',
        'requirements',
        'salary_range',
        'location',
        'job_type',        // full_time | part_time | contract | freelance
        'work_mode',       // remote | hybrid | on_site
        'experience_level',// junior | mid | senior
        'experience_required', // e.g. "5+ years"
        'company_name',
        'company_logo',    // URL to company logo/image
        'employer_id',
        'is_active',
        'category',
        'tags',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'salary_range' => 'array',
        'tags' => 'array',
    ];

    // Relationship with employer
    public function employer()
    {
        return $this->belongsTo(User::class, 'employer_id');
    }

    // Relationship with applications
    public function applications()
    {
        return $this->hasMany(Application::class);
    }
}