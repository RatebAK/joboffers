<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class CompanyProfile extends Model
{
    protected $collection = 'company_profiles';

    protected $fillable = [
        'employer_id',       // references User._id
        'name',
        'logo',              // URL
        'description',
        'location',          // e.g. "Mountain View, CA"
        'company_size',      // e.g. "100-500 employees"
        'industry',
        'website',
        'rating',            // float e.g. 4.5
        'review_count',      // int e.g. 1250
    ];

    protected $casts = [
        'rating'       => 'float',
        'review_count' => 'integer',
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
