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
        'industry',           // single display-label string (e.g. "Information Technology")
        'company_size',       // one of self::SIZES — canonical, shared by public & private
        'city',               // e.g. "Damascus"
        'country',            // e.g. "Syria"
        'phone_main',         // primary contact phone
        'phone_extra',        // secondary contact phone (optional)
        'phone_visible',      // bool — show phone_main to job seekers on public profile
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
        'phone_main'       => null,
        'phone_extra'      => null,
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
     *   expose_to_applicants: bool,   // surface safe fields on public profile
     *   address: string,
     *   industry_tags: string[],      // multi-value internal taxonomy (distinct from top-level `industry`)
     *   founded_year: int,
     *   website: string|null,
     *   social_media: {
     *     instagram, telegram, twitter, facebook, behance, github, linkedin
     *   }
     * }
     *
     * Fields NOT in private_info (they live at the top level):
     *   company_size  — canonical headcount bucket, set via updatePublic
     *   phone_main    — primary phone, visibility gated by phone_visible
     *   phone_extra   — secondary phone, always owner-only
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
