<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CompanyProfile;
use App\Models\JobPost;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class CompanyProfileController extends Controller
{
    /**
     * List companies
     *
     * Paginated list of company profiles. Supports filtering by search term, industry, minimum rating, and company size.
     *
     * @unauthenticated
     * @queryParam search string Filter by company name or location. Example: Google
     * @queryParam industry string Filter by industry (partial match). Example: Technology
     * @queryParam min_rating number Filter by minimum rating (0–5). Example: 4.0
     * @queryParam company_size string Filter by company size string (partial match). Example: 100-500
     * @queryParam per_page integer Number of results per page (max 100). Defaults to 15. Example: 10
     * @queryParam page integer Page number. Example: 1
     *
     * @response 200 {
     *   "data": [
     *     {
     *       "id": "664f1a2b3c4d5e6f7a8b9c0d",
     *       "name": "Google",
     *       "logo": "https://logo.clearbit.com/google.com",
     *       "cover_image": "https://images.unsplash.com/photo.jpg",
     *       "description": "A multinational technology company.",
     *       "location": "Mountain View, CA",
     *       "industry": "Technology",
     *       "website": "https://www.google.com",
     *       "founded": "1998",
     *       "employee_count": "100,000+ employees",
     *       "company_size": "100000+",
     *       "rating": 4.5,
     *       "review_count": 1250,
     *       "would_recommend": 85,
     *       "ceo_performance": 92,
     *       "open_positions": 3,
     *       "company_size_range": { "min": 100000, "isPlus": true }
     *     }
     *   ],
     *   "current_page": 1,
     *   "per_page": 15,
     *   "total": 1,
     *   "total_pages": 1,
     *   "next_page": null,
     *   "prev_page": null
     * }
     */
    public function index(Request $request)
    {
        $query = CompanyProfile::query();

        if ($search = $request->query('search')) {
            $regex = new \MongoDB\BSON\Regex($search, 'i');
            $query->where(function ($q) use ($regex) {
                $q->where('name', $regex)->orWhere('location', $regex);
            });
        }

        if ($industry = $request->query('industry')) {
            $query->where('industry', new \MongoDB\BSON\Regex($industry, 'i'));
        }

        if ($minRating = $request->query('min_rating')) {
            $query->where('rating', '>=', (float) $minRating);
        }

        if ($companySize = $request->query('company_size')) {
            $query->where('company_size', new \MongoDB\BSON\Regex($companySize, 'i'));
        }

        $perPage = min((int) ($request->query('per_page', 15)), 100);
        $paginator = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $paginator->getCollection()->transform(function ($profile) {
            return $this->withOpenPositions($profile);
        });

        return response()->json([
            'data'         => $paginator->items(),
            'current_page' => $paginator->currentPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
            'total_pages'  => $paginator->lastPage(),
            'next_page'    => $paginator->hasMorePages() ? $paginator->currentPage() + 1 : null,
            'prev_page'    => $paginator->currentPage() > 1 ? $paginator->currentPage() - 1 : null,
            'next_page_url'=> $paginator->nextPageUrl(),
            'prev_page_url'=> $paginator->previousPageUrl(),
        ]);
    }

    /**
     * Get company
     *
     * Returns a single company profile including embedded reviews, active job posts, rating breakdowns, and social media links.
     *
     * @unauthenticated
     * @urlParam id string required The company profile ID. Example: 664f1a2b3c4d5e6f7a8b9c0d
     *
     * @response 200 {
     *   "id": "664f1a2b3c4d5e6f7a8b9c0d",
     *   "name": "Google",
     *   "logo": "https://logo.clearbit.com/google.com",
     *   "cover_image": "https://images.unsplash.com/photo.jpg",
     *   "description": "A multinational technology company.",
     *   "founded": "1998",
     *   "employee_count": "100,000+ employees",
     *   "location": "Mountain View, CA",
     *   "website": "https://www.google.com",
     *   "industry": "Technology",
     *   "company_size": "100000+",
     *   "rating": 4.5,
     *   "review_count": 1250,
     *   "would_recommend": 85,
     *   "ceo_performance": 92,
     *   "social_media": {
     *     "linkedin": "https://www.linkedin.com/company/google",
     *     "twitter": "https://twitter.com/Google",
     *     "facebook": "https://www.facebook.com/Google",
     *     "instagram": "https://www.instagram.com/google"
     *   },
     *   "category_ratings": {
     *     "compensation": 4.2,
     *     "culture": 4.6,
     *     "work_life": 4.1,
     *     "diversity": 4.4,
     *     "management": 4.3
     *   },
     *   "reviews": [
     *     {
     *       "id": "1",
     *       "rating": 4,
     *       "user_name": "John Doe",
     *       "date": "27/01/2026",
     *       "position": "Former employee, last year at 2022",
     *       "recommend": false,
     *       "ceo_approval": true,
     *       "subratings": { "compensation": 4, "culture": 4, "work_life": 3, "diversity": 5, "management": 3 },
     *       "agrees": 5,
     *       "disagrees": 2
     *     }
     *   ],
     *   "jobs": [
     *     {
     *       "id": "664f1a2b3c4d5e6f7a8b9c0e",
     *       "display_id": "JOB-001",
     *       "company_name": "Google",
     *       "company_logo": "https://logo.clearbit.com/google.com",
     *       "title": "Senior Frontend Developer",
     *       "created_at": "2024-01-15",
     *       "roles": ["Frontend", "React"],
     *       "types": ["full_time", "remote"],
     *       "levels": ["senior"],
     *       "experience": "5+ years",
     *       "location": "San Francisco, CA"
     *     }
     *   ],
     *   "open_positions": 3,
     *   "company_size_range": { "min": 100000, "isPlus": true }
     * }
     * @response 404 { "message": "Company profile not found" }
     */
    public function show(string $id)
    {
        $profile = CompanyProfile::find($id);

        if (!$profile) {
            return response()->json(['message' => 'Company profile not found'], 404);
        }

        $data = $this->withOpenPositions($profile);

        // Attach active job posts for this company
        $jobs = JobPost::where('employer_id', $profile->employer_id)
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($job) => $this->formatJob($job))
            ->values()
            ->toArray();

        $data['jobs']    = $jobs;
        $data['reviews'] = $profile->reviews ?? [];

        return response()->json($data);
    }

    /**
     * Upsert company profile
     *
     * Create or update the authenticated employer's company profile. Calling this endpoint a second time updates the existing profile (one profile per employer).
     * `company_size` accepts either a plain string (`"100-500"`, `"500+"`) or a structured object `{ min, max?, isPlus }` — both are normalised to a canonical string before storage.
     * 
     * Note: Rating fields (rating, review_count, reviews, etc.) are read-only and managed by the system. They cannot be set by employers.
     *
     * @bodyParam name string required Company name. Max 150 chars. Example: Google
     * @bodyParam logo string URL to company logo. Example: https://logo.clearbit.com/google.com
     * @bodyParam cover_image string URL to cover/banner image. Example: https://images.unsplash.com/photo.jpg
     * @bodyParam description string Company description.
     * @bodyParam location string Headquarters location. Example: Mountain View, CA
     * @bodyParam company_size string|object Size string ("100-500", "500+") or object {min, max?, isPlus}. Example: 100-500
     * @bodyParam employee_count string Display string for employee count. Example: 100,000+ employees
     * @bodyParam industry string Industry name. Example: Technology
     * @bodyParam website string Company website URL. Example: https://www.google.com
     * @bodyParam founded string Year founded. Example: 1998
     * @bodyParam social_media object Social media links.
     * @bodyParam social_media.linkedin string LinkedIn URL. Example: https://www.linkedin.com/company/google
     * @bodyParam social_media.twitter string Twitter URL. Example: https://twitter.com/Google
     * @bodyParam social_media.facebook string Facebook URL. Example: https://www.facebook.com/Google
     * @bodyParam social_media.instagram string Instagram URL. Example: https://www.instagram.com/google
     *
     * @response 200 {
     *   "id": "664f1a2b3c4d5e6f7a8b9c0d",
     *   "name": "Google",
     *   "logo": "https://logo.clearbit.com/google.com",
     *   "cover_image": "https://images.unsplash.com/photo.jpg",
     *   "founded": "1998",
     *   "employee_count": "100,000+ employees",
     *   "location": "Mountain View, CA",
     *   "website": "https://www.google.com",
     *   "company_size": "100000+",
     *   "open_positions": 0,
     *   "company_size_range": { "min": 100000, "isPlus": true }
     * }
     * @response 422 { "errors": { "name": ["The name field is required."] } }
     */
    public function upsert(Request $request)
    {
        // Normalize structured company_size object to canonical string before validation
        if ($request->has('company_size') && is_array($request->input('company_size'))) {
            $cs = $request->input('company_size');
            $normalized = !empty($cs['isPlus'])
                ? "{$cs['min']}+"
                : "{$cs['min']}-{$cs['max']}";
            $request->merge(['company_size' => $normalized]);
        }

        $validator = Validator::make($request->all(), [
            'name'                  => 'required|string|max:150',
            'logo'                  => 'nullable|url',
            'cover_image'           => 'nullable|url',
            'description'           => 'nullable|string',
            'location'              => 'nullable|string|max:150',
            'company_size'          => 'nullable|string|max:100',
            'employee_count'        => 'nullable|string|max:100',
            'industry'              => 'nullable|string|max:100',
            'website'               => 'nullable|url',
            'founded'               => 'nullable|string|max:10',
            'social_media'          => 'nullable|array',
            'social_media.linkedin' => 'nullable|url',
            'social_media.twitter'  => 'nullable|url',
            'social_media.facebook' => 'nullable|url',
            'social_media.instagram'=> 'nullable|url',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $employerId = (string) Auth::user()->_id;

        $profile = CompanyProfile::updateOrCreate(
            ['employer_id' => $employerId],
            $validator->validated()
        );

        return response()->json($this->withOpenPositions($profile), 200);
    }

    /**
     * Format a JobPost for embedding in a company profile response.
     */
    private function formatJob(JobPost $job): array
    {
        return [
            'id'           => (string) $job->_id,
            'display_id'   => $job->job_id ?? ('JOB-' . strtoupper(substr((string) $job->_id, -6))),
            'company_name' => $job->company_name,
            'company_logo' => $job->company_logo,
            'title'        => $job->title,
            'created_at'   => optional($job->created_at)->toDateString(),
            'roles'        => $job->tags ?? [],
            'types'        => array_filter([$job->job_type, $job->work_mode]),
            'levels'       => array_filter([$job->experience_level]),
            'experience'   => $job->experience_required,
            'location'     => $job->location,
        ];
    }

    /**
     * Append live open_positions count to a profile.
     */
    private function withOpenPositions(CompanyProfile $profile): array
    {
        $data = $profile->toArray();
        $data['open_positions'] = JobPost::where('employer_id', $profile->employer_id)
            ->where('is_active', true)
            ->count();
        $data['company_size_range'] = $this->parseCompanySize($profile->company_size);
        return $data;
    }

    /**
     * Parse a company_size string like "100-500 employees" or "500+ employees"
     * into a structured { min, max?, isPlus } object for frontend consumption.
     */
    private function parseCompanySize(?string $size): ?array
    {
        if (!$size) return null;

        // Match "500+" or "500+ employees"
        if (preg_match('/^(\d+)\+/', $size, $m)) {
            return ['min' => (int) $m[1], 'isPlus' => true];
        }

        // Match "100-500" or "100-500 employees"
        if (preg_match('/^(\d+)-(\d+)/', $size, $m)) {
            return ['min' => (int) $m[1], 'max' => (int) $m[2], 'isPlus' => false];
        }

        return null;
    }
}
