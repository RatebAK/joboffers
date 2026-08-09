<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CompanyProfile;
use App\Models\JobPost;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CompanyProfileController extends Controller
{
    // -----------------------------------------------------------------------
    // Public endpoints (no auth required)
    // -----------------------------------------------------------------------

    /**
     * List companies
     *
     * Paginated list of company profiles with optional filters.
     *
     * @unauthenticated
     * @queryParam search string Filter by name or city. Example: Tammam
     * @queryParam industry string Partial match on industry. Example: Technology
     * @queryParam city string Partial match on city. Example: Damascus
     * @queryParam company_size string Exact enum value. Example: less_than_10
     * @queryParam min_rating number Minimum aggregate rating (0–5). Example: 3.5
     * @queryParam per_page integer Max 100, default 15. Example: 10
     * @queryParam page integer Page number. Example: 1
     */
    public function index(Request $request)
    {
        $query = CompanyProfile::query();

        if ($search = $request->query('search')) {
            $regex = new \MongoDB\BSON\Regex($search, 'i');
            $query->where(function ($q) use ($regex) {
                $q->where('name', $regex)->orWhere('city', $regex);
            });
        }

        if ($industry = $request->query('industry')) {
            $query->where('industry', new \MongoDB\BSON\Regex($industry, 'i'));
        }

        if ($city = $request->query('city')) {
            $query->where('city', new \MongoDB\BSON\Regex($city, 'i'));
        }

        if ($size = $request->query('company_size')) {
            $query->where('company_size', $size);
        }

        if ($minRating = $request->query('min_rating')) {
            $query->where('rating', '>=', (float) $minRating);
        }

        $perPage = min((int) ($request->query('per_page', 15)), 100);
        $paginator = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $paginator->getCollection()->transform(fn($p) => $this->publicView($p));

        return response()->json([
            'data'         => $paginator->items(),
            'current_page' => $paginator->currentPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
            'total_pages'  => $paginator->lastPage(),
            'next_page'    => $paginator->hasMorePages() ? $paginator->currentPage() + 1 : null,
            'prev_page'    => $paginator->currentPage() > 1 ? $paginator->currentPage() - 1 : null,
        ]);
    }

    /**
     * Get company
     *
     * Returns a single company public profile including active job posts, reviews, and ratings.
     * When the employer has set `private_info.expose_to_applicants = true`, the response also
     * includes `address`, `industry_tags`, `founded_year`, `website`, and `social_media`.
     * `phone_main` is included when `phone_visible` is true.
     *
     * @unauthenticated
     * @urlParam id string required Company profile ID. Example: 664f1a2b3c4d5e6f7a8b9c0d
     * @response 404 { "message": "Company profile not found" }
     */
    public function show(string $id)
    {
        $profile = CompanyProfile::find($id);

        if (!$profile) {
            return response()->json(['message' => 'Company profile not found'], 404);
        }

        $data = $this->publicView($profile);

        $data['jobs'] = JobPost::where('employer_id', $profile->employer_id)
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($job) => $this->formatJobCard($job))
            ->values()
            ->toArray();

        $data['reviews']         = $profile->reviews ?? [];
        $data['category_ratings'] = $profile->category_ratings ?? null;

        return response()->json($data);
    }

    // -----------------------------------------------------------------------
    // Employer-only endpoints
    // -----------------------------------------------------------------------

    /**
     * Get my company profile (owner view)
     *
     * Returns the full company profile for the authenticated employer, including
     * `private_info`, active `jobs`, `reviews`, `category_ratings`, and all rating fields.
     * This is a superset of the public company endpoint.
     *
     * **Field locations at a glance:**
     * - `company_size`, `industry`, `phone_main`, `phone_extra` — top-level fields
     * - `industry_tags`, `address`, `founded_year`, `website`, `social_media` — inside `private_info`
     *
     * @response 404 { "message": "No company profile found. Create one first." }
     */
    public function myProfile()
    {
        $profile = $this->resolveProfile();

        if (!$profile) {
            return response()->json(['message' => 'No company profile found. Create one first.'], 404);
        }

        return response()->json($this->ownerView($profile));
    }

    /**
     * Update public company info
     *
     * Creates or updates the employer's company public profile.
     *
     * **Phone fields:** use `phone_main` (primary) and `phone_extra` (optional secondary).
     * Set `phone_visible: true` to expose `phone_main` on the public profile.
     *
     * **Size vs industry:** `company_size` is the canonical headcount bucket (shared across public
     * and private views). `industry` is a single display-label string. For multi-value internal
     * industry taxonomy, use `industry_tags` in the private info endpoint.
     *
     * @bodyParam name string Company name (required on first creation, max 150). Example: Tammam company
     * @bodyParam description string Free-text description. Example: We build software.
     * @bodyParam industry string Single display-label for the company's primary industry. Example: Information Technology Services
     * @bodyParam company_size string Enum: less_than_10 | 10_to_50 | 51_to_200 | 201_to_500 | 501_to_1000 | 1001_to_5000 | more_than_5000. Example: less_than_10
     * @bodyParam city string City name. Example: Damascus
     * @bodyParam country string Country. Example: Syria
     * @bodyParam phone_main string Primary contact phone. Example: 0932444357
     * @bodyParam phone_extra string Secondary contact phone (optional). Example: 0911000000
     * @bodyParam phone_visible boolean Expose phone_main on the public profile. Example: false
     * @bodyParam email string Contact email. Example: contact@tammam.co
     * @response 422 { "errors": {} }
     */
    public function updatePublic(Request $request)
    {
        $existing = $this->resolveProfile();
        $isCreate = $existing === null;

        $validator = Validator::make($request->all(), [
            'name'         => ($isCreate ? 'required' : 'sometimes') . '|string|max:150',
            'description'  => 'nullable|string',
            'industry'     => 'nullable|string|max:150',
            'company_size' => 'nullable|in:' . implode(',', CompanyProfile::SIZES),
            'city'         => 'nullable|string|max:100',
            'country'      => 'nullable|string|max:100',
            'phone_main'   => 'nullable|string|max:30',
            'phone_extra'  => 'nullable|string|max:30',
            'phone_visible'=> 'nullable|boolean',
            'email'        => 'nullable|email|max:150',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $employerId = (string) Auth::user()->_id;

        if ($isCreate) {
            $slug = $this->makeSlug($data['name']);
            $profile = CompanyProfile::create(array_merge($data, [
                'employer_id' => $employerId,
                'slug'        => $slug,
                // Initialise rating aggregates at zero
                'rating'          => 0,
                'review_count'    => 0,
                'would_recommend' => 0,
                'ceo_performance' => 0,
                'category_ratings' => [
                    'compensation' => 0,
                    'culture'      => 0,
                    'work_life'    => 0,
                    'diversity'    => 0,
                    'management'   => 0,
                ],
                'reviews' => [],
            ]));
        } else {
            $existing->update($data);
            $profile = $existing->fresh();
        }

        return response()->json($this->ownerView($profile), $isCreate ? 201 : 200);
    }

    /**
     * Update private company info
     *
     * Updates the employer-only private info block stored in `private_info`.
     * Set `expose_to_applicants: true` to surface `address`, `industry_tags`,
     * `founded_year`, `website`, and `social_media` on the public company endpoint.
     *
     * **Field guide — what lives here vs. the public endpoint:**
     * | Field | Where |
     * |---|---|
     * | `company_size` | Public endpoint only (canonical, no duplicate here) |
     * | `industry` | Public endpoint — single display label |
     * | `industry_tags` | Private info — multi-value internal taxonomy array |
     * | `phone_main` / `phone_extra` | Public endpoint (visibility gated by `phone_visible`) |
     * | `address`, `founded_year`, `website`, `social_media` | Private info only |
     *
     * @bodyParam expose_to_applicants boolean Expose safe private fields on the public profile. Example: false
     * @bodyParam address string Full street address. Example: Mazzeh, Damascus
     * @bodyParam industry_tags string[] Internal multi-value industry taxonomy (distinct from the public `industry` display label). Example: ["Software Development", "IT Consulting"]
     * @bodyParam founded_year integer Year the company was founded. Example: 2018
     * @bodyParam website string Company website URL. Example: https://tammam.co
     * @bodyParam social_media object Social media links.
     * @bodyParam social_media.linkedin string Example: https://linkedin.com/company/tammam
     * @bodyParam social_media.github string
     * @bodyParam social_media.twitter string
     * @bodyParam social_media.facebook string
     * @bodyParam social_media.instagram string
     * @bodyParam social_media.telegram string
     * @bodyParam social_media.behance string
     * @response 404 { "message": "No company profile found. Create one first." }
     * @response 422 { "errors": {} }
     */
    public function updatePrivate(Request $request)
    {
        $profile = $this->resolveProfile();

        if (!$profile) {
            return response()->json(['message' => 'No company profile found. Create one first.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'expose_to_applicants'   => 'nullable|boolean',
            'address'                => 'nullable|string|max:255',
            'industry_tags'          => 'nullable|array|min:1',
            'industry_tags.*'        => 'string|max:150',
            'founded_year'           => 'nullable|integer|min:1800|max:' . date('Y'),
            'website'                => 'nullable|url',
            'social_media'           => 'nullable|array',
            'social_media.instagram' => 'nullable|url',
            'social_media.telegram'  => 'nullable|url',
            'social_media.twitter'   => 'nullable|url',
            'social_media.facebook'  => 'nullable|url',
            'social_media.behance'   => 'nullable|url',
            'social_media.github'    => 'nullable|url',
            'social_media.linkedin'  => 'nullable|url',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        // Merge into existing private_info to support partial updates
        $existing = $profile->private_info ?? [];
        $profile->update(['private_info' => array_merge($existing, $validated)]);

        return response()->json($this->ownerView($profile->fresh()));
    }

    /**
     * Upload company logo
     *
     * Uploads and replaces the company logo. The image is stored on Cloudinary.
     * Accepts JPEG, PNG, WebP; max 2 MB. Any previously uploaded logo is automatically deleted.
     *
     * @bodyParam logo file required The logo image file. Accepted: jpeg, png, webp. Max 2 MB.
     * @response 200 scenario="Success" {"logo": "https://res.cloudinary.com/dd8vgoh/image/upload/company-logos/abc123.jpg"}
     * @response 404 scenario="No profile" {"message": "No company profile found. Create one first."}
     * @response 422 scenario="Validation error" {"logo": ["The logo field is required."]}
     */
    public function uploadLogo(Request $request)
    {
        $profile = $this->resolveProfile();

        if (!$profile) {
            return response()->json(['message' => 'No company profile found. Create one first.'], 404);
        }

        $request->validate([
            'logo' => 'required|image|mimes:jpeg,png,webp|max:2048',
        ]);

        // Delete old logo from Cloudinary
        if ($profile->logo_public_id) {
            Storage::disk('cloudinary')->delete($profile->logo_public_id);
        }

        $path = $request->file('logo')->store('company-logos', 'cloudinary');
        $profile->update([
            'logo' => Storage::disk('cloudinary')->url($path),
            'logo_public_id' => $path,
        ]);

        return response()->json(['logo' => $profile->fresh()->logo]);
    }

    /**
     * Upload company cover image
     *
     * Uploads and replaces the company cover/banner image. The image is stored on Cloudinary.
     * Accepts JPEG, PNG, WebP; max 4 MB. Any previously uploaded cover image is automatically deleted.
     *
     * @bodyParam cover_image file required The cover image file. Accepted: jpeg, png, webp. Max 4 MB.
     * @response 200 scenario="Success" {"cover_image": "https://res.cloudinary.com/dd8vgoh/image/upload/company-covers/abc123.jpg"}
     * @response 404 scenario="No profile" {"message": "No company profile found. Create one first."}
     * @response 422 scenario="Validation error" {"cover_image": ["The cover image field is required."]}
     */
    public function uploadCoverImage(Request $request)
    {
        $profile = $this->resolveProfile();

        if (!$profile) {
            return response()->json(['message' => 'No company profile found. Create one first.'], 404);
        }

        $request->validate([
            'cover_image' => 'required|image|mimes:jpeg,png,webp|max:4096',
        ]);

        // Delete old cover from Cloudinary
        if ($profile->cover_image_public_id) {
            Storage::disk('cloudinary')->delete($profile->cover_image_public_id);
        }

        $path = $request->file('cover_image')->store('company-covers', 'cloudinary');
        $profile->update([
            'cover_image' => Storage::disk('cloudinary')->url($path),
            'cover_image_public_id' => $path,
        ]);

        return response()->json(['cover_image' => $profile->fresh()->cover_image]);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** Resolve the authenticated employer's company profile (or null). */
    private function resolveProfile(): ?CompanyProfile
    {
        return CompanyProfile::where('employer_id', (string) Auth::user()->_id)->first();
    }

    /** Public-facing shape: no private_info, phone_main hidden when phone_visible=false.
     *  If private_info.expose_to_applicants is true, merges safe private fields in. */
    private function publicView(CompanyProfile $profile): array
    {
        $data = $profile->only([
            '_id', 'name', 'slug', 'logo', 'cover_image', 'description',
            'industry', 'company_size', 'city', 'country', 'email',
            'rating', 'review_count', 'would_recommend', 'ceo_performance',
            'created_at', 'updated_at',
        ]);

        if ($profile->phone_visible) {
            $data['phone_main'] = $profile->phone_main;
        }

        // Merge exposed private fields when employer opts in
        $private = $profile->private_info ?? [];
        if (!empty($private['expose_to_applicants'])) {
            foreach (['address', 'industry_tags', 'founded_year', 'website', 'social_media'] as $key) {
                if (isset($private[$key])) {
                    $data[$key] = $private[$key];
                }
            }
        }

        $data['open_positions'] = JobPost::where('employer_id', $profile->employer_id)
            ->where('is_active', true)->count();

        return $data;
    }

    /** Owner-facing shape: includes private_info, jobs, reviews, and rating breakdown. */
    private function ownerView(CompanyProfile $profile): array
    {
        $data = $profile->toArray();

        // Always return a fully-shaped private_info so the frontend knows the schema
        $data['private_info'] = array_merge([
            'expose_to_applicants' => false,
            'address'              => null,
            'industry_tags'        => [],
            'founded_year'         => null,
            'website'              => null,
            'social_media'         => [
                'linkedin'  => null,
                'github'    => null,
                'twitter'   => null,
                'facebook'  => null,
                'instagram' => null,
                'telegram'  => null,
                'behance'   => null,
            ],
        ], $profile->private_info ?? []);

        $data['open_positions'] = JobPost::where('employer_id', $profile->employer_id)
            ->where('is_active', true)->count();

        $data['jobs'] = JobPost::where('employer_id', $profile->employer_id)
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($job) => $this->formatJobCard($job))
            ->values()
            ->toArray();

        $data['reviews']          = $profile->reviews ?? [];
        $data['category_ratings'] = $profile->category_ratings ?? null;

        return $data;
    }

    /** Format a job post as a compact card for embedding in company show response. */
    private function formatJobCard(JobPost $job): array
    {
        return [
            'id'           => (string) $job->_id,
            'job_id'       => $job->job_id,
            'title'        => $job->title,
            'city'         => $job->city,
            'job_type'     => $job->job_type,
            'work_mode'    => $job->work_mode,
            'job_level'    => $job->job_level,
            'experience_years' => $job->experience_years,
            'roles'        => $job->roles ?? [],
            'tags'         => $job->tags ?? [],
            'created_at'   => optional($job->created_at)->toDateString(),
        ];
    }

    /** Generate a unique slug from a company name. */
    private function makeSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 2;
        while (CompanyProfile::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }
        return $slug;
    }
}
