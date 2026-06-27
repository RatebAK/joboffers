<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\CompanyProfile;
use App\Models\JobPost;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class JobPostController extends Controller
{
    // -----------------------------------------------------------------------
    // Shared validation rules
    // -----------------------------------------------------------------------

    private function postRules(bool $required = true): array
    {
        $r = $required ? 'required' : 'sometimes';

        return [
            // Communication
            'communication_method'  => "{$r}|in:by_phone,by_forsa,by_website",
            'communication_value'   => 'nullable|string|max:255',

            // Employee specification
            'title'                 => "{$r}|string|max:150",
            'roles'                 => 'nullable|array',
            'roles.*'               => 'string|max:100',
            'portfolio_required'    => 'nullable|boolean',
            'cover_letter_required' => 'nullable|boolean',
            'gender'                => 'nullable|in:male,female,no_preference',
            'age_from'              => 'nullable|integer|min:16|max:100',
            'age_to'                => 'nullable|integer|min:16|max:100|gte:age_from',
            'education_level'       => 'nullable|in:high_school,diploma,bachelor,master,phd,any',
            'job_level'             => 'nullable|in:entry,junior,mid,senior,manager,director',
            'experience_years'      => 'nullable|integer|min:0|max:50',
            'languages'             => 'nullable|array',
            'languages.*'           => 'string|max:50',

            // Work details
            'vacancies'             => "{$r}|integer|min:1",
            'job_type'              => "{$r}|in:full_time,part_time,contract,freelance",
            'work_mode'             => 'nullable|in:remote,hybrid,on_site',
            'city'                  => "{$r}|string|max:100",
            'address'               => 'nullable|string|max:255',
            'salary_from'           => 'nullable|integer|min:0',
            'salary_to'             => 'nullable|integer|min:0|gte:salary_from',
            'currency'              => 'nullable|string|max:10',
            'display_salary'        => 'nullable|boolean',
            'incentives'            => 'nullable|string|max:500',

            // Job info
            'description'           => "{$r}|string",
            'requirements'          => 'nullable|string',
            'questions'             => 'nullable|array',
            'questions.*.question'  => 'required_with:questions|string|max:500',
            'questions.*.required'  => 'nullable|boolean',

            // Meta
            'category'              => 'nullable|string|max:100',
            'tags'                  => 'nullable|array',
            'tags.*'                => 'string|max:50',
            'expires_at'            => 'nullable|date|after:today',
        ];
    }

    // -----------------------------------------------------------------------
    // Public endpoints
    // -----------------------------------------------------------------------

    /**
     * List jobs
     *
     * Paginated list of active job posts with optional filters.
     *
     * @unauthenticated
     * @queryParam keyword string Search in title, description, company name. Example: Laravel
     * @queryParam city string Filter by city. Example: Damascus
     * @queryParam job_type string Filter: full_time | part_time | contract | freelance. Example: full_time
     * @queryParam work_mode string Filter: remote | hybrid | on_site. Example: remote
     * @queryParam job_level string Filter: entry | junior | mid | senior | manager | director. Example: senior
     * @queryParam experience_years integer Filter by required experience (lte). Example: 3
     * @queryParam category string Filter by category. Example: Engineering
     * @queryParam min_salary integer Minimum salary_from. Example: 500
     * @queryParam max_salary integer Maximum salary_to. Example: 3000
     * @queryParam tag string Filter by tag. Example: React
     * @queryParam communication_method string Filter: by_phone | by_forsa | by_website. Example: by_forsa
     * @queryParam per_page integer Max 100, default 15. Example: 10
     * @queryParam page integer Page number. Example: 1
     * @response 200 {
     *   "data": [
     *     {
     *       "_id": "664f1a2b3c4d5e6f7a8b9c0d",
     *       "job_id": "JOB-0001",
     *       "employer_id": "664f1a2b3c4d5e6f7a8b9c0e",
     *       "company_profile_id": "664f1a2b3c4d5e6f7a8b9c0f",
     *       "company_name": "Acme Corp",
     *       "company_logo": "https://example.com/logo.png",
     *       "title": "Senior Laravel Developer",
     *       "description": "We are looking for...",
     *       "requirements": "3+ years Laravel experience.",
     *       "roles": ["Backend", "PHP"],
     *       "category": "Engineering",
     *       "tags": ["Laravel", "MongoDB"],
     *       "job_type": "full_time",
     *       "work_mode": "on_site",
     *       "job_level": "senior",
     *       "education_level": "bachelor",
     *       "experience_years": 3,
     *       "languages": ["Arabic", "English"],
     *       "vacancies": 2,
     *       "city": "Damascus",
     *       "address": "Mazzeh Street 12",
     *       "salary_from": 500,
     *       "salary_to": 1000,
     *       "currency": "USD",
     *       "display_salary": true,
     *       "incentives": "Monthly bonuses",
     *       "gender": "no_preference",
     *       "age_from": null,
     *       "age_to": null,
     *       "portfolio_required": false,
     *       "cover_letter_required": true,
     *       "communication_method": "by_forsa",
     *       "communication_value": null,
     *       "questions": [{ "question": "Describe your last project.", "required": true }],
     *       "is_active": true,
     *       "expires_at": "2026-12-31T00:00:00.000000Z",
     *       "created_at": "2026-01-15T10:00:00.000000Z",
     *       "updated_at": "2026-01-15T10:00:00.000000Z"
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
        $query = JobPost::where('is_active', true);

        if ($keyword = $request->query('keyword')) {
            $regex = new \MongoDB\BSON\Regex($keyword, 'i');
            $query->where(function ($q) use ($regex) {
                $q->where('title', $regex)
                  ->orWhere('description', $regex)
                  ->orWhere('company_name', $regex);
            });
        }

        if ($city = $request->query('city')) {
            $query->where('city', new \MongoDB\BSON\Regex($city, 'i'));
        }

        foreach (['job_type', 'work_mode', 'job_level', 'category', 'communication_method'] as $exact) {
            if ($v = $request->query($exact)) {
                $query->where($exact, $v);
            }
        }

        if ($exp = $request->query('experience_years')) {
            $query->where('experience_years', '<=', (int) $exp);
        }

        if ($min = $request->query('min_salary')) {
            $query->where('salary_from', '>=', (int) $min);
        }

        if ($max = $request->query('max_salary')) {
            $query->where('salary_to', '<=', (int) $max);
        }

        if ($tag = $request->query('tag')) {
            $query->where('tags', $tag);
        }

        $perPage = min((int) ($request->query('per_page', 15)), 100);
        $paginator = $query->orderBy('created_at', 'desc')->paginate($perPage);

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
     * Get job
     *
     * Returns a single job post by ID. Inactive posts are still returned so
     * employers/seekers can view their own closed postings — enforce access
     * control at the client if needed.
     *
     * @unauthenticated
     * @urlParam id string required Job post ID. Example: 664f1a2b3c4d5e6f7a8b9c0d
     * @response 200 scenario="Success" {
     *   "_id": "664f1a2b3c4d5e6f7a8b9c0d",
     *   "job_id": "JOB-0001",
     *   "employer_id": "664f1a2b3c4d5e6f7a8b9c0e",
     *   "company_profile_id": "664f1a2b3c4d5e6f7a8b9c0f",
     *   "company_name": "Acme Corp",
     *   "company_logo": "https://example.com/logo.png",
     *   "title": "Senior Laravel Developer",
     *   "description": "We are looking for an experienced Laravel developer...",
     *   "requirements": "3+ years Laravel, MongoDB experience preferred.",
     *   "roles": ["Backend", "PHP"],
     *   "category": "Engineering",
     *   "tags": ["Laravel", "MongoDB", "PHP"],
     *   "job_type": "full_time",
     *   "work_mode": "on_site",
     *   "job_level": "senior",
     *   "education_level": "bachelor",
     *   "experience_years": 3,
     *   "languages": ["Arabic", "English"],
     *   "vacancies": 2,
     *   "city": "Damascus",
     *   "address": "Mazzeh Street 12",
     *   "salary_from": 500,
     *   "salary_to": 1000,
     *   "currency": "USD",
     *   "display_salary": true,
     *   "incentives": "Monthly bonuses",
     *   "gender": "no_preference",
     *   "age_from": null,
     *   "age_to": null,
     *   "portfolio_required": false,
     *   "cover_letter_required": true,
     *   "communication_method": "by_forsa",
     *   "communication_value": null,
     *   "questions": [
     *     { "question": "Describe your last project.", "required": true }
     *   ],
     *   "is_active": true,
     *   "expires_at": "2026-12-31T00:00:00.000000Z",
     *   "created_at": "2026-01-15T10:00:00.000000Z",
     *   "updated_at": "2026-01-15T10:00:00.000000Z",
     *   "company": {
     *     "_id": "664f1a2b3c4d5e6f7a8b9c0f",
     *     "slug": "acme-corp",
     *     "name": "Acme Corp",
     *     "logo": "https://example.com/logo.png",
     *     "description": "We build software.",
     *     "city": "Damascus",
     *     "country": "Syria",
     *     "social_media": {
     *       "linkedin": "https://linkedin.com/company/acme",
     *       "github": null,
     *       "twitter": null,
     *       "facebook": null,
     *       "instagram": null,
     *       "telegram": null,
     *       "behance": null
     *     }
     *   }
     * }
     * @response 404 { "message": "Job post not found" }
     */
    public function show(string $id)
    {
        $post = JobPost::find($id);

        if (!$post) {
            return response()->json(['message' => 'Job post not found'], 404);
        }

        $data = $post->toArray();

        // Embed company snippet for the "About Company" section
        $company = CompanyProfile::find($post->company_profile_id);
        if ($company) {
            $snippet = [
                '_id'         => (string) $company->_id,
                'slug'        => $company->slug,
                'name'        => $company->name,
                'logo'        => $company->logo,
                'description' => $company->description,
                'city'        => $company->city,
                'country'     => $company->country,
                'social_media' => $company->private_info['social_media'] ?? null,
            ];
            $data['company'] = $snippet;
        }

        return response()->json($data);
    }

    // -----------------------------------------------------------------------
    // Employer endpoints
    // -----------------------------------------------------------------------

    /**
     * Create job post
     *
     * Creates a new job post. Company name and logo are automatically populated from
     * the employer's company profile — they cannot be overridden here.
     *
     * An employer must have an approved company profile before creating a job post.
     *
     * @bodyParam communication_method string required One of: by_phone, by_forsa, by_website. Example: by_forsa
     * @bodyParam communication_value string Phone or website URL (required when method is by_phone or by_website). Example: null
     * @bodyParam title string required Job title. Example: Senior Laravel Developer
     * @bodyParam roles string[] Role tags. Example: ["Backend","PHP"]
     * @bodyParam portfolio_required boolean Example: false
     * @bodyParam cover_letter_required boolean Example: true
     * @bodyParam gender string male | female | no_preference. Example: no_preference
     * @bodyParam age_from integer Example: null
     * @bodyParam age_to integer Example: null
     * @bodyParam education_level string high_school | diploma | bachelor | master | phd | any. Example: bachelor
     * @bodyParam job_level string entry | junior | mid | senior | manager | director. Example: mid
     * @bodyParam experience_years integer Years of experience required. Example: 3
     * @bodyParam languages string[] Example: ["Arabic","English"]
     * @bodyParam vacancies integer required Number of open slots. Example: 2
     * @bodyParam job_type string required full_time | part_time | contract | freelance. Example: full_time
     * @bodyParam work_mode string remote | hybrid | on_site. Example: on_site
     * @bodyParam city string required. Example: Damascus
     * @bodyParam address string Full address. Example: Mazzeh Street 12
     * @bodyParam salary_from integer. Example: 500
     * @bodyParam salary_to integer. Example: 1000
     * @bodyParam currency string. Example: USD
     * @bodyParam display_salary boolean Show salary on listing. Example: true
     * @bodyParam incentives string Commissions / bonuses / insurance info. Example: Monthly bonuses
     * @bodyParam description string required Job summary and responsibilities. Example: We are looking for...
     * @bodyParam requirements string Skills and expertise. Example: 3+ years Laravel experience.
     * @bodyParam questions object[] Custom screening questions.
     * @bodyParam questions[].question string required. Example: Describe your last project.
     * @bodyParam questions[].required boolean. Example: true
     * @bodyParam tags string[] Searchable tags. Example: ["Laravel","MongoDB"]
     * @bodyParam category string. Example: Engineering
     * @bodyParam expires_at date ISO date for expiry. Example: 2026-12-31
     *
     * @response 201 scenario="Created" {
     *   "_id": "664f1a2b3c4d5e6f7a8b9c0d",
     *   "job_id": "JOB-0001",
     *   "employer_id": "664f1a2b3c4d5e6f7a8b9c0e",
     *   "company_profile_id": "664f1a2b3c4d5e6f7a8b9c0f",
     *   "company_name": "Acme Corp",
     *   "company_logo": "https://example.com/logo.png",
     *   "title": "Senior Laravel Developer",
     *   "is_active": true,
     *   "created_at": "2026-01-15T10:00:00.000000Z",
     *   "updated_at": "2026-01-15T10:00:00.000000Z"
     * }
     * @response 404 { "message": "You must create a company profile before posting a job." }
     * @response 422 { "errors": {} }
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        // Enforce one-company rule: post must be linked to employer's existing profile
        $company = CompanyProfile::where('employer_id', (string) $user->_id)->first();

        if (!$company) {
            return response()->json(
                ['message' => 'You must create a company profile before posting a job.'],
                404
            );
        }

        $validator = Validator::make($request->all(), $this->postRules(true));

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        $count = JobPost::count() + 1;
        $jobId = 'JOB-' . str_pad($count, 4, '0', STR_PAD_LEFT);

        $post = JobPost::create(array_merge($data, [
            'job_id'             => $jobId,
            'employer_id'        => (string) $user->_id,
            'company_profile_id' => (string) $company->_id,
            // Denormalise company identity — immutable on post
            'company_name'       => $company->name,
            'company_logo'       => $company->logo,
            'is_active'          => true,
        ]));

        return response()->json($post, 201);
    }

    /**
     * Update job post
     *
     * Updates an existing job post. `company_name` and `company_logo` are read-only
     * and always sourced from the employer's company profile.
     *
     * @urlParam id string required Job post ID. Example: 664f1a2b3c4d5e6f7a8b9c0d
     * @response 200 scenario="Updated" {
     *   "_id": "664f1a2b3c4d5e6f7a8b9c0d",
     *   "job_id": "JOB-0001",
     *   "title": "Updated Job Title",
     *   "is_active": true,
     *   "updated_at": "2026-06-01T12:00:00.000000Z"
     * }
     * @response 403 { "message": "Forbidden" }
     * @response 404 { "message": "Job post not found" }
     * @response 422 { "errors": {} }
     */
    public function update(Request $request, string $id)
    {
        $post = JobPost::find($id);

        if (!$post) {
            return response()->json(['message' => 'Job post not found'], 404);
        }

        $user = Auth::user();

        if ((string) $post->employer_id !== (string) $user->_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validator = Validator::make($request->all(), $this->postRules(false));

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Never allow overriding company identity
        unset($data['company_name'], $data['company_logo'], $data['company_profile_id']);

        // Sync company logo in case employer updated it since posting
        $company = CompanyProfile::where('employer_id', (string) $user->_id)->first();
        if ($company) {
            $data['company_logo'] = $company->logo;
        }

        $post->update($data);

        return response()->json($post->fresh());
    }

    /**
     * Delete job post
     *
     * @urlParam id string required Job post ID. Example: 664f1a2b3c4d5e6f7a8b9c0d
     * @response 200 { "message": "Job post deleted successfully" }
     * @response 403 { "message": "Forbidden" }
     * @response 404 { "message": "Job post not found" }
     */
    public function destroy(string $id)
    {
        $post = JobPost::find($id);

        if (!$post) {
            return response()->json(['message' => 'Job post not found'], 404);
        }

        $user = Auth::user();

        if ((string) $post->employer_id !== (string) $user->_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $post->delete();

        return response()->json(['message' => 'Job post deleted successfully']);
    }

    /**
     * My job posts
     *
     * Returns all job posts by the authenticated employer, each with an `application_count` field.
     *
     * @response 200 scenario="Success" [
     *   {
     *     "_id": "664f1a2b3c4d5e6f7a8b9c0d",
     *     "job_id": "JOB-0001",
     *     "employer_id": "664f1a2b3c4d5e6f7a8b9c0e",
     *     "company_name": "Acme Corp",
     *     "company_logo": "https://example.com/logo.png",
     *     "title": "Senior Laravel Developer",
     *     "job_type": "full_time",
     *     "work_mode": "on_site",
     *     "city": "Damascus",
     *     "is_active": true,
     *     "vacancies": 2,
     *     "salary_from": 500,
     *     "salary_to": 1000,
     *     "currency": "USD",
     *     "expires_at": "2026-12-31T00:00:00.000000Z",
     *     "created_at": "2026-01-15T10:00:00.000000Z",
     *     "updated_at": "2026-01-15T10:00:00.000000Z",
     *     "application_count": 7
     *   }
     * ]
     */
    public function myPosts()
    {
        $user = Auth::user();

        $posts = JobPost::where('employer_id', (string) $user->_id)
            ->orderBy('created_at', 'desc')
            ->get();

        $result = $posts->map(function ($post) {
            $data = $post->toArray();
            $data['application_count'] = Application::where('job_post_id', (string) $post->_id)->count();
            return $data;
        });

        return response()->json($result);
    }

    /**
     * Deactivate job post
     *
     * Sets `is_active` to false, hiding the post from public listings.
     *
     * @urlParam id string required Job post ID. Example: 664f1a2b3c4d5e6f7a8b9c0d
     * @response 200 {}
     * @response 403 { "message": "Forbidden" }
     * @response 404 { "message": "Job post not found" }
     */
    public function deactivate(string $id)
    {
        $post = JobPost::find($id);

        if (!$post) {
            return response()->json(['message' => 'Job post not found'], 404);
        }

        $user = Auth::user();

        if ((string) $post->employer_id !== (string) $user->_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $post->update(['is_active' => false]);

        return response()->json($post->fresh());
    }
}
