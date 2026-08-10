<?php

namespace App\Http\Controllers\API;

use App\Exceptions\CvAnalysisException;
use App\Http\Controllers\Controller;
use App\Models\JobPost;
use App\Models\JobSeekerProfile;
use App\Services\JobMatchingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class JobMatchingController extends Controller
{
    /**
     * Match candidates to job description
     *
     * Uses AI to match job seekers to a job description based on skills and requirements.
     * Returns candidates ordered by relevance with matched skills scores.
     *
     * @bodyParam job_description string required The job description or requirements. Max 5000 chars. Example: Senior React developer with 5+ years experience
     * @bodyParam limit integer Maximum number of candidates to return. Min: 1, Max: 50. Example: 10
     *
     * @response 200 {
     *   "extracted_requirements": ["React", "5+ years", "Senior level"],
     *   "candidates": [
     *     {
     *       "user_id": "664f1a2b3c4d5e6f7a8b9c0d",
     *       "resume_id": "664f1a2b3c4d5e6f7a8b9c0d",
     *       "full_name": "Jane Smith",
     *       "matched_skills_score": 85,
     *       "skills": ["React", "TypeScript", "Node.js"],
     *       "profile_url": "/api/employer/job-seeker/664f1a2b3c4d5e6f7a8b9c0d"
     *     }
     *   ]
     * }
     * @response 422 { "message": "Job matching failed", "reason": "Invalid job description" }
     * @response 502 { "message": "Job matching service unavailable" }
     */
    public function matchCandidates(Request $request, JobMatchingService $jobMatchingService)
    {
        $validator = Validator::make($request->all(), [
            'job_description' => 'required|string|max:5000',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        $jobDescription = $request->input('job_description');
        $limit = $request->input('limit', 10);

        try {
            $result = $jobMatchingService->matchJobToCandidates($jobDescription, $limit);
        } catch (CvAnalysisException $e) {
            $statusCode = $e->getHttpStatusCode();

            if ($statusCode === 422) {
                return response()->json([
                    'message' => 'Job matching failed',
                    'reason' => $e->getMessage(),
                ], 422);
            }

            return response()->json([
                'message' => 'Job matching service unavailable',
            ], 502);
        }

        // Enrich candidates with profile URLs and map resume_id to user_id
        $enrichedCandidates = collect($result['candidates'])->map(function ($candidate) {
            $profile = JobSeekerProfile::where('user_id', $candidate['resume_id'])->first();

            return [
                'user_id'              => $candidate['resume_id'],
                'resume_id'            => $candidate['resume_id'],
                'full_name'            => $candidate['name'] ?? $candidate['full_name'] ?? null,
                'matched_skills_score' => $candidate['matched_skills_score'] ?? 0,
                'matched_skills'       => $candidate['matched_skills'] ?? $candidate['skills'] ?? [],
                'profile_url'          => $profile ? "/api/employer/job-seeker/{$candidate['resume_id']}" : null,
            ];
        })->toArray();

        return response()->json([
            'extracted_requirements' => $result['extracted_requirements'],
            'candidates'             => $enrichedCandidates,
        ], 200);
    }

    /**
     * Match candidates to existing job post
     *
     * Uses the job post's description to find matching candidates via AI.
     * Employer must own the job post.
     *
     * @urlParam job_post_id string required Job post MongoDB ID. Example: 664f1a2b3c4d5e6f7a8b9c0d
     *
     * @bodyParam limit integer Maximum number of candidates to return. Min: 1, Max: 50. Example: 10
     *
     * @response 200 {
     *   "job_post": { "id": "664f1a2b3c4d5e6f7a8b9c0d", "title": "Senior React Developer" },
     *   "extracted_requirements": ["React", "Senior level"],
     *   "candidates": []
     * }
     * @response 403 { "message": "You do not own this job post" }
     * @response 404 { "message": "Job post not found" }
     */
    public function matchCandidatesToJobPost(string $jobPostId, Request $request, JobMatchingService $jobMatchingService)
    {
        $user = $request->user();

        $jobPost = JobPost::find($jobPostId);

        if (! $jobPost) {
            return response()->json([
                'message' => 'Job post not found',
            ], 404);
        }

        // Check ownership
        if ($jobPost->employer_id !== $user->_id) {
            return response()->json([
                'message' => 'You do not own this job post',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        $limit = $request->input('limit', 10);

        // Use job description for matching
        $jobDescription = $jobPost->description ?? $jobPost->title;

        try {
            $result = $jobMatchingService->matchJobToCandidates($jobDescription, $limit);
        } catch (CvAnalysisException $e) {
            $statusCode = $e->getHttpStatusCode();

            if ($statusCode === 422) {
                return response()->json([
                    'message' => 'Job matching failed',
                    'reason' => $e->getMessage(),
                ], 422);
            }

            return response()->json([
                'message' => 'Job matching service unavailable',
            ], 502);
        }

        // Enrich candidates
        $enrichedCandidates = collect($result['candidates'])->map(function ($candidate) {
            $profile = JobSeekerProfile::where('user_id', $candidate['resume_id'])->first();

            return [
                'user_id'              => $candidate['resume_id'],
                'resume_id'            => $candidate['resume_id'],
                'full_name'            => $candidate['name'] ?? $candidate['full_name'] ?? null,
                'matched_skills_score' => $candidate['matched_skills_score'] ?? 0,
                'matched_skills'       => $candidate['matched_skills'] ?? $candidate['skills'] ?? [],
                'profile_url'          => $profile ? "/api/employer/job-seeker/{$candidate['resume_id']}" : null,
            ];
        })->toArray();

        return response()->json([
            'job_post' => [
                'id' => $jobPost->_id,
                'title' => $jobPost->title,
            ],
            'extracted_requirements' => $result['extracted_requirements'],
            'candidates' => $enrichedCandidates,
        ], 200);
    }
}
