<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class CompanyProfile extends Model
{
    protected $collection = 'company_profiles';

    // Company size enum values
    const SIZES = [
        'less_than_10',
        '10_to_50',
        '51_to_200',
        '201_to_500',
        '501_to_1000',
        '1001_to_5000',
        'more_than_5000',
    ];

    protected $fillable = [
        'employer_id',        // references User._id (immutable after creation)
        'name',               // company name — immutable after first set
        'slug',               // url-friendly name, auto-generated
        // Public fields
        'logo',               // URL
        'logo_public_id',     // Cloudinary public_id for deletion
        'cover_image',        // URL
        'cover_image_public_id', // Cloudinary public_id for deletion
        'description',
        'industry',           // single industry string
        'company_size',       // one of self::SIZES
        'city',               // e.g. "Damascus"
        'country',            // e.g. "Syria"
        'phone',              // contact phone
        'phone_visible',      // bool — show phone to job seekers
        'email',              // contact email
        // Private / general info (employer-only unless expose_to_applicants = true)
        'private_info',       // embedded object — see structure below
        // Aggregate rating fields (system-managed, read-only for employers)
        'rating',             // float 0–5
        'review_count',       // int
        'would_recommend',    // int percentage
        'ceo_performance',    // int percentage
        'category_ratings',   // { compensation, culture, work_life, diversity, management }
        'reviews',            // array of review objects
    ];

    protected $attributes = [
        // Public fields
        'logo'             => null,
        'logo_public_id'   => null,
        'cover_image'      => null,
        'cover_image_public_id' => null,
        'description'      => null,
        'industry'         => null,
        'company_size'     => null,
        'city'             => null,
        'country'          => null,
        'phone'            => null,
        'phone_visible'    => false,
        'email'            => null,
        // Private info block
        'private_info'     => null,
        // Rating aggregates
        'rating'           => 0,
        'review_count'     => 0,
        'would_recommend'  => 0,
        'ceo_performance'  => 0,
        'category_ratings' => null,
        'reviews'          => null,
    ];

    protected $casts = [
        'rating'           => 'float',
        'review_count'     => 'integer',
        'would_recommend'  => 'integer',
        'ceo_performance'  => 'integer',
        'phone_visible'    => 'boolean',
        'category_ratings' => 'array',
        'reviews'          => 'array',
        'private_info'     => 'array',
    ];

    /**
     * private_info sub-document shape:
     * {
     *   expose_to_applicants: bool,   // whether this block is visible on job posts
     *   address: string,
     *   industries: string[],         // multi-select, at least one
     *   company_size: string,         // same enum, can differ from public field
     *   founded_year: int,
     *   phone_main: string,
     *   phone_extra: string|null,
     *   website: string|null,
     *   social_media: {
     *     instagram, telegram, twitter, facebook, behance, github, linkedin
     *   }
     * }
     */

    public function user()
    {
        return $this->belongsTo(User::class, 'employer_id');
    }

    public function openPositionsCount(): int
    {
        return JobPost::where('employer_id', (string) $this->employer_id)
            ->where('is_active', true)
            ->count();
    }
}
