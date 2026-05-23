<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\JobPost;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class JobPostController extends Controller
{
    /**
     * List jobs
     *
     * Paginated list of active job posts with optional filters.
     *
     * @unauthenticated
     * @queryParam keyword string Search in title, description, company name. Example: Laravel
     * @queryParam location string Filter by location (partial match). Example: Beirut
     * @queryParam job_type string Filter by type. Example: full_time
     * @queryParam work_mode string Filter by work mode. Example: remote
     * @queryParam experience_level string Filter by level. Example: senior
     * @queryParam category string Filter by category. Example: Engineering
     * @queryParam min_salary integer Minimum salary range. Example: 2000
     * @queryParam max_salary integer Maximum salary range. Example: 8000
     * @queryParam tag string Filter by tag. Example: React
     * @queryParam per_page integer Results per page (max 100). Defaults to 15. Example: 10
     * @queryParam page integer Page number. Example: 1
     *
     * @response 200 {
     *   "data": [
     *     {
     *       "id": "664f1a2b3c4d5e6f7a8b9c0d",
     *       "job_id": "JOB-001",
     *       "title": "Senior Laravel Developer",
     *       "description": "We are looking for...",
     *       "requirements": "Minimum 3 years...",
     *       "company_name": "Acme Corp",
     *       "company_logo": "https://logo.clearbit.com/acme.com",
     *       "job_type": "full_time",
     *       "work_mode": "remote",
     *       "experience_level": "senior",
     *       "experience_required": "5+ years",
     *       "location": "Beirut, Lebanon",
     *       "category": "Engineering",
     *       "salary_range": { "min": 2000, "max": 4000, "currency": "USD" },
     *       "tags": ["Laravel", "PHP"],
     *       "is_active": true,
     *       "employer_id": "664f1a2b3c4d5e6f7a8b9c0e",
     *       "created_at": "2024-01-15T00:00:00Z"
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

        if ($location = $request->query('location')) {
            $query->where('location', new \MongoDB\BSON\Regex($location, 'i'));
        }

        if ($jobType = $request->query('job_type')) {
            $query->where('job_type', $jobType);
        }

        if ($workMode = $request->query('work_mode')) {
            $query->where('work_mode', $workMode);
        }

        if ($experienceLevel = $request->query('experience_level')) {
            $query->where('experience_level', $experienceLevel);
        }

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        if ($minSalary = $request->query('min_salary')) {
            $query->where('salary_range.min', '>=', (int) $minSalary);
        }

        if ($maxSalary = $request->query('max_salary')) {
            $query->where('salary_range.max', '<=', (int) $maxSalary);
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
            'next_page_url'=> $paginator->nextPageUrl(),
            'prev_page_url'=> $paginator->previousPageUrl(),
        ]);
    }

    /**
     * Get job
     *
     * Returns a single active job post by ID.
     *
     * @unauthenticated
     * @urlParam id string required The job post ID. Example: 664f1a2b3c4d5e6f7a8b9c0d
     *
     * @response 200 {
     *   "id": "664f1a2b3c4d5e6f7a8b9c0d",
     *   "job_id": "JOB-001",
     *   "title": "Senior Laravel Developer",
     *   "company_name": "Acme Corp",
     *   "job_type": "full_time",
     *   "work_mode": "remote",
     *   "experience_level": "senior",
     *   "location": "Beirut, Lebanon",
     *   "is_active": true
     * }
     * @response 404 { "message": "Job post not found" }
     */
    public function show(string $id)
    {
        $post = JobPost::find($id);

        if (!$post) {
            return response()->json(['message' => 'Job post not found'], 404);
        }

        return response()->json($post);
    }

    /**
     * Create job post
     *
     * Creates a new job post owned by the authenticated employer.
     *
     * @bodyParam title string required Job title. Max 150 chars. Example: Senior Laravel Developer
     * @bodyParam description string required Full job description. Example: We are looking for...
     * @bodyParam requirements string required Job requirements. Example: Minimum 3 years of Laravel experience.
     * @bodyParam company_name string required Company name. Max 150 chars. Example: Acme Corp
     * @bodyParam company_logo string URL to company logo. Example: https://logo.clearbit.com/acme.com
     * @bodyParam job_type string required One of: full_time, part_time, contract, freelance. Example: full_time
     * @bodyParam work_mode string One of: remote, hybrid, on_site. Example: remote
     * @bodyParam experience_level string One of: junior, mid, senior. Example: senior
     * @bodyParam experience_required string Display string for required experience. Example: 5+ years
     * @bodyParam location string Example: Beirut, Lebanon
     * @bodyParam category string Example: Engineering
     * @bodyParam salary_range object Salary range object.
     * @bodyParam salary_range.min integer Example: 2000
     * @bodyParam salary_range.max integer Example: 4000
     * @bodyParam salary_range.currency string Example: USD
     * @bodyParam tags string[] Array of tags. Example: ["Laravel","PHP"]
     *
     * @response 201 {
     *   "id": "664f1a2b3c4d5e6f7a8b9c0d",
     *   "job_id": "JOB-001",
     *   "title": "Senior Laravel Developer",
     *   "is_active": true,
     *   "employer_id": "664f1a2b3c4d5e6f7a8b9c0e"
     * }
     * @response 422 { "errors": { "title": ["The title field is required."] } }
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title'               => 'required|string|max:150',
            'description'         => 'required|string',
            'requirements'        => 'required|string',
            'company_name'        => 'required|string|max:150',
            'company_logo'        => 'nullable|url',
            'job_type'            => 'required|in:full_time,part_time,contract,freelance',
            'work_mode'           => 'nullable|in:remote,hybrid,on_site',
            'experience_level'    => 'nullable|in:junior,mid,senior',
            'experience_required' => 'nullable|string|max:50',
            'location'            => 'nullable|string',
            'category'            => 'nullable|string',
            'salary_range'        => 'nullable|array',
            'salary_range.min'    => 'nullable|integer|min:0',
            'salary_range.max'    => 'nullable|integer|min:0',
            'salary_range.currency' => 'nullable|string',
            'tags'                => 'nullable|array',
        ]);

        $user = Auth::user();

        // Generate a human-readable job ID
        $count = JobPost::count() + 1;
        $jobId = 'JOB-' . str_pad($count, 3, '0', STR_PAD_LEFT);

        $post = JobPost::create(array_merge($validated, [
            'job_id'      => $jobId,
            'employer_id' => (string) $user->_id,
            'is_active'   => true,
        ]));

        return response()->json($post, 201);
    }

    /**
     * Update job post
     *
     * Updates an existing job post. Only the owning employer can update it.
     *
     * @urlParam id string required The job post ID. Example: 664f1a2b3c4d5e6f7a8b9c0d
     * @bodyParam title string Example: Updated Title
     * @bodyParam description string
     * @bodyParam requirements string
     * @bodyParam company_name string
     * @bodyParam company_logo string URL
     * @bodyParam job_type string One of: full_time, part_time, contract, freelance.
     * @bodyParam work_mode string One of: remote, hybrid, on_site.
     * @bodyParam experience_level string One of: junior, mid, senior.
     * @bodyParam experience_required string
     * @bodyParam location string
     * @bodyParam category string
     * @bodyParam salary_range object
     * @bodyParam tags string[]
     * @bodyParam is_active boolean
     *
     * @response 200 { "id": "664f1a2b3c4d5e6f7a8b9c0d", "title": "Updated Title" }
     * @response 403 { "message": "Forbidden" }
     * @response 404 { "message": "Job post not found" }
     */
    public function update(Request $request, string $id)
    {
        $post = JobPost::find($id);

        if (!$post) {
            return response()->json(['message' => 'Job post not found'], 404);
        }

        $user = Auth::user();

        if ($post->employer_id !== (string) $user->_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'title'               => 'sometimes|string|max:150',
            'description'         => 'sometimes|string',
            'requirements'        => 'sometimes|string',
            'company_name'        => 'sometimes|string|max:150',
            'company_logo'        => 'nullable|url',
            'job_type'            => 'sometimes|in:full_time,part_time,contract,freelance',
            'work_mode'           => 'nullable|in:remote,hybrid,on_site',
            'experience_level'    => 'nullable|in:junior,mid,senior',
            'experience_required' => 'nullable|string|max:50',
            'location'            => 'nullable|string',
            'category'            => 'nullable|string',
            'salary_range'        => 'nullable|array',
            'salary_range.min'    => 'nullable|integer|min:0',
            'salary_range.max'    => 'nullable|integer|min:0',
            'salary_range.currency' => 'nullable|string',
            'tags'                => 'nullable|array',
            'is_active'           => 'sometimes|boolean',
        ]);

        $post->update($validated);

        return response()->json($post);
    }

    /**
     * Delete job post
     *
     * Permanently deletes a job post. Only the owning employer can delete it.
     *
     * @urlParam id string required The job post ID. Example: 664f1a2b3c4d5e6f7a8b9c0d
     *
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

        if ($post->employer_id !== (string) $user->_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $post->delete();

        return response()->json(['message' => 'Job post deleted successfully']);
    }

    /**
     * My job posts
     *
     * Returns all job posts created by the authenticated employer, each with an application count.
     *
     * @response 200 [
     *   {
     *     "id": "664f1a2b3c4d5e6f7a8b9c0d",
     *     "job_id": "JOB-001",
     *     "title": "Senior Laravel Developer",
     *     "is_active": true,
     *     "application_count": 5
     *   }
     * ]
     */
    public function myPosts()
    {
        $user = Auth::user();

        $posts = JobPost::where('employer_id', (string) $user->_id)->get();

        $result = $posts->map(function ($post) {
            $data = $post->toArray();
            $data['application_count'] = Application::where('job_post_id', $post->_id)->count();
            return $data;
        });

        return response()->json($result);
    }

    /**
     * Deactivate job post
     *
     * Sets `is_active` to false, hiding the post from public listings. Only the owning employer can deactivate it.
     *
     * @urlParam id string required The job post ID. Example: 664f1a2b3c4d5e6f7a8b9c0d
     *
     * @response 200 { "id": "664f1a2b3c4d5e6f7a8b9c0d", "is_active": false }
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

        if ($post->employer_id !== (string) $user->_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $post->update(['is_active' => false]);

        return response()->json($post);
    }
}
