<?php

namespace App\Http\Controllers\API;

use App\Exceptions\CvAnalysisException;
use App\Http\Controllers\Controller;
use App\Models\JobPost;
use App\Models\JobSeekerProfile;
use App\Models\User;
use App\Services\JobMatchingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class JobMatchingController extends Controller
{
    /**
     * Match candidates to job description
     *
     * Uses AI to match job seekers to a job description based on skills and requirements.
     * Returns full candidate profiles (employer view) enriched with AI match data.
     *
     * @bodyParam job_description string required The job description or requirements. Max 5000 chars. Example: Senior React developer with 5+ years experience
     * @bodyParam limit integer Maximum number of candidates to return. Min: 1, Max: 50. Example: 10
     *
     * @response 200 {
     *   "extracted_requirements": ["php", "laravel", "mongodb"],
     *   "candidates": [
     *     {
     *       "id": "664f1a2b3c4d5e6f7a8b9c0d",
     *       "user_id": "664f1a2b3c4d5e6f7a8b9c0e",
     *       "name": "Jane Smith",
     *       "email": "jane@example.com",
     *       "image": null,
     *       "current_job_title": "Backend Developer",
     *       "city": "Damascus",
     *       "ats_score": 82,
     *       "ai_skills": ["PHP", "Laravel"],
     *       "ai_summary": "Experienced backend developer...",
     *       "is_actively_seeking": true,
     *       "matched_skills_score": 85,
     *       "matched_skills": ["php", "laravel"],
     *       "profile_url": "/api/employer/seekers/664f1a2b3c4d5e6f7a8b9c0e"
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

        $enrichedCandidates = $this->enrichCandidates($result['candidates']);

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

        $enrichedCandidates = $this->enrichCandidates($result['candidates']);

        return response()->json([
            'job_post' => [
                'id'    => (string) $jobPost->_id,
                'title' => $jobPost->title,
            ],
            'extracted_requirements' => $result['extracted_requirements'],
            'candidates'             => $enrichedCandidates,
        ], 200);
    }

    /**
     * Enrich AI candidate results with full profile data from our DB.
     * Candidates whose user_id doesn't exist locally are silently dropped.
     */
    private function enrichCandidates(array $candidates): array
    {
        $profileFields = [
            'current_job_title', 'current_job_status', 'job_level', 'job_types', 'job_roles',
            'years_of_experience', 'education_level', 'expected_salary', 'salary_range_from',
            'salary_range_to', 'is_actively_seeking', 'work_cities', 'city',
            'experience_summary', 'social_links',
            'skills', 'education_history', 'work_experience',
            'ats_score', 'ai_skills', 'ai_summary', 'ai_work_history', 'ai_education_history',
            'ai_languages', 'ai_projects', 'ai_social_links', 'ai_overall_evaluation',
            'ai_detected_language', 'ai_analyzed_at',
        ];

        return collect($candidates)
            ->map(function ($candidate) use ($profileFields) {
                $userId  = $candidate['resume_id'] ?? null;
                $profile = $userId ? JobSeekerProfile::where('user_id', $userId)->first() : null;

                if (! $profile) {
                    return null;
                }

                $user = User::find($userId);

                return array_merge(
                    [
                        'id'      => (string) $profile->_id,
                        'user_id' => $userId,
                        'name'    => $user?->name,
                        'email'   => $user?->email,
                        'image'   => $profile->image,
                    ],
                    collect($profile->toArray())->only($profileFields)->toArray(),
                    [
                        'matched_skills_score' => $candidate['matched_skills_score'] ?? 0,
                        'matched_skills'       => $candidate['matched_skills'] ?? [],
                        'profile_url'          => "/api/employer/seekers/{$userId}",
                    ]
                );
            })
            ->filter()
            ->values()
            ->toArray();
    }
}
