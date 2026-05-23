<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class CompanyProfile extends Model
{
    protected $collection = 'company_profiles';

    protected $fillable = [
        'employer_id',        // references User._id
        'name',
        'logo',               // URL
        'cover_image',        // URL — banner/hero image
        'description',
        'location',           // e.g. "Mountain View, CA"
        'company_size',       // e.g. "100-500 employees"
        'employee_count',     // display string e.g. "100,000+ employees"
        'industry',
        'website',
        'founded',            // year string e.g. "1998"
        'social_media',       // { linkedin, twitter, facebook, instagram }
        // Aggregate rating fields
        'rating',             // float e.g. 4.5
        'review_count',       // int e.g. 1250
        'would_recommend',    // int percentage e.g. 85
        'ceo_performance',    // int percentage e.g. 92
        'category_ratings',   // { compensation, culture, work_life, diversity, management }
        // Embedded reviews array
        'reviews',            // array of review objects
    ];

    protected $casts = [
        'rating'           => 'float',
        'review_count'     => 'integer',
        'would_recommend'  => 'integer',
        'ceo_performance'  => 'integer',
        'category_ratings' => 'array',
        'social_media'     => 'array',
        'reviews'          => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'employer_id');
    }

    /**
     * Count of active job posts for this company.
     */
    public function openPositionsCount(): int
    {
        return JobPost::where('employer_id', (string) $this->employer_id)
            ->where('is_active', true)
            ->count();
    }
}
