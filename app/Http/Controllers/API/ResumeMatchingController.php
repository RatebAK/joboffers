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
     *   "matches_found": 5,
     *   "jobs": [
     *     {
     *       "_id": "664f1a2b3c4d5e6f7a8b9c0d",
     *       "job_id": "JOB-0001",
     *       "title": "Senior Laravel Developer",
     *       "company_name": "Acme Corp",
     *       "city": "Damascus",
     *       "job_type": "full_time",
     *       "is_active": true,
     *       "matched_skills": ["php", "laravel"],
     *       "matched_skills_score": 3
     *     }
     *   ]
     * }
     * @response 422 { "message": "No CV found on your profile. Please upload and analyze your CV first." }
     * @response 502 { "message": "Resume matching service unavailable" }
     */
    public function matchResume(Request $request, ResumeMatchingService $matchingService)
    {
        set_time_limit(300);

        $user    = $request->user();
        $profile = $user->jobSeekerProfile;

        if (! ($profile->cv_file_path ?? null)) {
            return response()->json([
                'message' => 'No CV found on your profile. Please upload and analyze your CV first.',
            ], 422);
        }

        try {
            $result = $matchingService->matchResumeToJobs((string) $user->_id);
        } catch (CvAnalysisException $e) {
            if ($e->getHttpStatusCode() === 422) {
                return response()->json([
                    'message' => 'Resume matching failed',
                    'reason'  => $e->getMessage(),
                ], 422);
            }

            return response()->json([
                'message'        => 'Resume matching service unavailable',
                'payload_sent'   => [
                    'resume_id' => (string) $user->_id,
                    'limit'     => 10,
                ],
            ], 502);
        }

        $jobs = collect($result['jobs'])->map(function ($job) {
            $dbJob = JobPost::where('job_id', $job['job_id'])->first();

            if (! $dbJob) {
                return null;
            }

            return array_merge($dbJob->toArray(), [
                'matched_skills'       => $job['matched_skills'] ?? [],
                'matched_skills_score' => $job['matched_skills_score'] ?? 0,
            ]);
        })->filter()->values()->toArray();

        return response()->json([
            'matches_found' => $result['matches_found'],
            'jobs'          => $jobs,
        ]);
    }
}
