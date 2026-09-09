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
     * Uses the job seeker's uploaded CV to find AI-matched job recommendations.
     * AI references are resolved to current local job posts so clients receive
     * the complete job-post payload plus match data.
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
                'message' => 'Resume matching service unavailable',
                'debug'   => [
                    'endpoint'     => config('services.resume_matching.url'),
                    'method'       => 'POST',
                    'content_type' => 'application/x-www-form-urlencoded',
                    'payload_sent' => [
                        'resume_id' => (string) $user->_id,
                        'limit'     => 10,
                    ],
                    'ai_error' => $e->getDiagnostic() ?: [
                        'type'    => 'application',
                        'message' => $e->getMessage(),
                    ],
                ],
            ], 502);
        }

        $userId = (string) $user->_id;
        $jobs = collect($result['jobs'])->map(function (array $aiJob) use ($userId) {
            // The AI currently returns Mongo document IDs in `job_id`; older
            // integrations may return the human-readable JOB-xxxx identifier.
            $reference = $aiJob['job_id'] ?? $aiJob['_id'] ?? null;
            if (blank($reference)) {
                return null;
            }

            $post = JobPost::find((string) $reference)
                ?? JobPost::where('job_id', (string) $reference)->first();

            if (! $post) {
                return null;
            }

            $data = $post->toArray();
            $data['has_applied'] = Application::where('user_id', $userId)
                ->where('job_post_id', (string) $post->_id)
                ->exists();

            $company = CompanyProfile::find($post->company_profile_id);
            if ($company) {
                $data['company'] = [
                    '_id'          => (string) $company->_id,
                    'slug'         => $company->slug,
                    'name'         => $company->name,
                    'logo'         => $company->logo,
                    'description'  => $company->description,
                    'city'         => $company->city,
                    'country'      => $company->country,
                    'social_media' => $company->private_info['social_media'] ?? null,
                ];
            }

            $data['matched_skills'] = $aiJob['matched_skills'] ?? [];
            $data['matched_skills_score'] = $aiJob['matched_skills_score'] ?? 0;

            return $data;
        })->filter()->values()->all();

        return response()->json([
            'matches_found' => count($jobs),
            'jobs'          => $jobs,
        ]);
    }
}
