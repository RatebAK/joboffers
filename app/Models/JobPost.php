<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class JobPost extends Model
{
    protected $collection = 'job_posts';

    protected $fillable = [
        'title',
        'description',
        'requirements',
        'salary_range',
        'location',
        'job_type',
        'company_name',
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