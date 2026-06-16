<?php

namespace App\Http\Controllers\API;

use App\Exceptions\CvAnalysisException;
use App\Http\Controllers\Controller;
use App\Models\JobPost;
use App\Services\ResumeMatchingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ResumeMatchingController extends Controller
{
    /**
     * Match resume to available jobs
     *
     * Upload a resume and get AI-matched job recommendations from the database.
     * Returns jobs ranked by match percentage and ATS compatibility.
     *
     * @bodyParam resume file required PDF/DOC/DOCX resume file, max 10 MB.
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
     *       "job_url": "/api/jobs/job_001"
     *     }
     *   ]
     * }
     * @response 422 { "errors": { "resume": ["The resume field is required."] } }
     * @response 502 { "message": "Resume matching service unavailable" }
     */
    public function matchResume(Request $request, ResumeMatchingService $matchingService)
    {
        $validator = Validator::make($request->all(), [
            'resume' => 'required|file|mimes:pdf,doc,docx|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        // Store file temporarily
        $storedPath = $request->file('resume')->store('temp_resumes', 'public');
        $resumeFilePath = 'public/temp_resumes/'.basename($storedPath);

        try {
            $result = $matchingService->matchResumeToJobs($resumeFilePath);
        } catch (CvAnalysisException $e) {
            // Clean up temp file
            Storage::delete($resumeFilePath);

            $statusCode = $e->getHttpStatusCode();

            if ($statusCode === 422) {
                return response()->json([
                    'message' => 'Resume matching failed',
                    'reason' => $e->getMessage(),
                ], 422);
            }

            return response()->json([
                'message' => 'Resume matching service unavailable',
            ], 502);
        }

        // Clean up temp file after successful processing
        Storage::delete($resumeFilePath);

        // Enrich jobs with URLs to our database
        $enrichedJobs = collect($result['recommended_jobs'])->map(function ($job) {
            // Try to find job in our database
            $dbJob = JobPost::find($job['job_id']);

            return [
                'job_id' => $job['job_id'],
                'title' => $job['title'] ?? null,
                'company' => $job['company'] ?? null,
                'location' => $job['location'] ?? null,
                'matched_skills_count' => $job['matched_skills_count'] ?? 0,
                'match_percentage' => $job['match_percentage'] ?? '0%',
                'ats_compatibility_score' => $job['ats_compatibility_score'] ?? '0%',
                'job_url' => $dbJob ? "/api/jobs/{$job['job_id']}" : null,
                'exists_in_db' => (bool) $dbJob,
            ];
        })->toArray();

        return response()->json([
            'matches_found' => $result['matches_found'],
            'recommended_jobs' => $enrichedJobs,
        ], 200);
    }
}
