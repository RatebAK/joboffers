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
     * Public: paginated list of active job posts with optional filters.
     * @unauthenticated
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
     * Public: single job post by ID.
     * @unauthenticated
     * @urlParam id string required The job post ID. Example: 6a04ca4809826695330cc475
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
     * Employer: create a new job post.
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
     * Employer: update own job post.
     * @urlParam id string required The job post ID. Example: 6a04ca4809826695330cc475
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
     * Employer: delete own job post.
     * @urlParam id string required The job post ID. Example: 6a04ca4809826695330cc475
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
     * Employer: list own job posts with application counts.
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
     * Employer: deactivate a job post.
     * @urlParam id string required The job post ID. Example: 6a04ca4809826695330cc475
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
