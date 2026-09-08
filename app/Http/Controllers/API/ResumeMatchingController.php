<?php

namespace App\Http\Controllers\API;

use App\Exceptions\CvAnalysisException;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\CompanyProfile;
use App\Models\JobPost;
use App\Services\ResumeMatchingService;
use Illuminate\Http\Request;

class ResumeMatchingController extends Controller
{
    /**
     * Match resume to available jobs
     *
     * Uses the job seeker's analyzed CV to find AI-matched job recommendations,
     * returned as full job post objects (same shape as GET /jobs/{id}) with
     * match data appended.
     *
     * @response 200 {
     *   "matches_found": 2,
     *   "jobs": [
     *     {
     *       "_id": "664f1a2b3c4d5e6f7a8b9c0d",
     *       "job_id": "JOB-0001",
     *       "title": "Senior Laravel Developer",
     *       "city": "Damascus",
     *       "job_type": "full_time",
     *       "is_active": true,
     *       "has_applied": false,
     *       "company": { "_id": "...", "name": "Acme Corp", "logo": "..." },
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

            return response()->json(['message' => 'Resume matching service unavailable'], 502);
        }

        $userId = (string) $user->_id;

        $jobs = collect($result['jobs'])->map(function ($aiJob) use ($userId) {
            $post = JobPost::where('job_id', $aiJob['job_id'])->first();

            if (! $post) {
                return null;
            }

            $data = $post->toArray();

            // has_applied flag — same as show()
            $data['has_applied'] = Application::where('user_id', $userId)
                ->where('job_post_id', (string) $post->_id)
                ->exists();

            // company snippet — same as show()
            $company = CompanyProfile::find($post->company_profile_id);
            if ($company) {
                $data['company'] = [
                    '_id'         => (string) $company->_id,
                    'slug'        => $company->slug,
                    'name'        => $company->name,
                    'logo'        => $company->logo,
                    'description' => $company->description,
                    'city'        => $company->city,
                    'country'     => $company->country,
                    'social_media' => $company->private_info['social_media'] ?? null,
                ];
            }

            // append AI match data
            $data['matched_skills']       = $aiJob['matched_skills'] ?? [];
            $data['matched_skills_score'] = $aiJob['matched_skills_score'] ?? 0;

            return $data;
        })->filter()->values()->toArray();

        return response()->json([
            'matches_found' => count($jobs),
            'jobs'          => $jobs,
        ]);
    }
}
