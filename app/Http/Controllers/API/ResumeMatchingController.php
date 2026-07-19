<?php

namespace App\Http\Controllers\API;

use App\Exceptions\CvAnalysisException;
use App\Http\Controllers\Controller;
use App\Models\JobPost;
use App\Services\ResumeMatchingService;
use Illuminate\Http\Request;

class ResumeMatchingController extends Controller
{
    /**
     * Match resume to available jobs
     *
     * Uses the job seeker's uploaded CV (from their profile) to find AI-matched job
     * recommendations from the database, ranked by match percentage and ATS compatibility.
     *
     * Requires the seeker to have an uploaded and analyzed CV on their profile.
     *
     * @response 200 {
     *   "matches_found": 25,
     *   "recommended_jobs": [
     *     {
     *       "job_id": "job_001",
     *       "title": "Backend Python Developer",
     *       "company": "TechStream Solutions",
     *       "location": "Remote",
     *       "matched_skills_count": 4,
     *       "match_percentage": "66%",
     *       "ats_compatibility_score": "69%",
     *       "job_url": "/api/jobs/job_001",
     *       "exists_in_db": true
     *     }
     *   ]
     * }
     * @response 422 { "message": "No CV found on your profile. Please upload and analyze your CV first." }
     * @response 502 { "message": "Resume matching service unavailable" }
     */
    public function matchResume(Request $request, ResumeMatchingService $matchingService)
    {
        $user    = $request->user();
        $profile = $user->jobSeekerProfile;

        $cvUrl = $profile->cv_file_path ?? null;

        if (! $cvUrl) {
            return response()->json([
                'message' => 'No CV found on your profile. Please upload and analyze your CV first.',
            ], 422);
        }

        try {
            $result = $matchingService->matchResumeToJobs($cvUrl);
        } catch (CvAnalysisException $e) {
            if ($e->getHttpStatusCode() === 422) {
                return response()->json([
                    'message' => 'Resume matching failed',
                    'reason'  => $e->getMessage(),
                ], 422);
            }

            return response()->json([
                'message' => 'Resume matching service unavailable',
            ], 502);
        }

        $enrichedJobs = collect($result['recommended_jobs'])->map(function ($job) {
            $dbJob = JobPost::find($job['job_id']);

            return [
                'job_id'                 => $job['job_id'],
                'title'                  => $job['title'] ?? null,
                'company'                => $job['company'] ?? null,
                'location'               => $job['location'] ?? null,
                'matched_skills_count'   => $job['matched_skills_count'] ?? 0,
                'match_percentage'       => $job['match_percentage'] ?? '0%',
                'ats_compatibility_score'=> $job['ats_compatibility_score'] ?? '0%',
                'job_url'                => $dbJob ? "/api/jobs/{$job['job_id']}" : null,
                'exists_in_db'           => (bool) $dbJob,
            ];
        })->toArray();

        return response()->json([
            'matches_found'    => $result['matches_found'],
            'recommended_jobs' => $enrichedJobs,
        ]);
    }
}
