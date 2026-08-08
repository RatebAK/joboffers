<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\JobSeekerProfile;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\CvAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminReanalysisController extends Controller
{
    /**
     * Trigger manual CV re-analysis for a job seeker
     *
     * [NEW API] Forces a fresh CV re-analysis for a specific job seeker. Useful for fixing stale AI data
     * or offering as a paid support action. Sets `analysis_status` to `processing` immediately, then
     * runs the analysis synchronously.
     *
     * The target user must have the `employee` role and must have a CV on file.
     *
     * @urlParam userId string required The job seeker's user ID. Example: 664f1a2b3c4d5e6f7a8b9c0d
     *
     * @response 200 {
     *   "message": "CV re-analysis triggered successfully.",
     *   "user_id": "664f1a2b3c4d5e6f7a8b9c0d",
     *   "analysis_status": "processing"
     * }
     * @response 422 { "message": "No CV file found for this user" }
     * @response 404 { "message": "User not found" }
     */
    public function reanalyze(Request $request, string $userId, CvAnalysisService $cvService): JsonResponse
    {
        $actor = $request->user();

        // Find user and validate role
        $user = User::find($userId);
        if (! $user || ! $user->hasRole('employee')) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // Find profile and validate CV exists
        $profile = JobSeekerProfile::where('user_id', $userId)->first();
        if (! $profile || empty($profile->cv_file_path)) {
            return response()->json(['message' => 'No CV file found for this user'], 422);
        }

        // Mark as processing
        $profile->update([
            'analysis_status'      => 'processing',
            'analysis_started_at'  => now(),
            'analysis_completed_at'=> null,
            'analysis_error'       => null,
        ]);

        // Write audit log before running analysis (synchronous per spec)
        AuditLogService::log(
            action:     'cv_reanalysis_triggered',
            actorId:    (string) $actor->_id,
            actorName:  $actor->name,
            targetId:   $userId,
            targetType: 'User',
            metadata:   ['triggered_by' => 'admin_manual']
        );

        // Dispatch analysis asynchronously (fire-and-forget style — update happens in the same flow)
        try {
            $analysis = $cvService->analyze($profile->cv_file_path, $userId, $profile->resume_file_type);

            $profile->update([
                'ai_full_name'         => $analysis['full_name']       ?? null,
                'ai_email'             => $analysis['email']           ?? null,
                'ai_phone'             => $analysis['phone']           ?? null,
                'ai_location'          => $analysis['location']        ?? null,
                'ai_summary'           => $analysis['summary']         ?? null,
                'ai_skills'            => $analysis['skills']          ?? [],
                'ai_work_history'      => $analysis['work_history']    ?? [],
                'ai_education_history' => $analysis['education_history'] ?? [],
                'ai_projects'          => $analysis['projects']        ?? [],
                'ai_languages'         => $analysis['languages']       ?? [],
                'ai_overall_evaluation'=> $analysis['overall_evaluation'] ?? null,
                'ats_score'            => $analysis['ats_score']       ?? null,
                'ai_analyzed_at'       => now(),
                'analysis_status'      => 'completed',
                'analysis_completed_at'=> now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Admin-triggered CV re-analysis failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);

            $profile->update([
                'analysis_status'      => 'error',
                'analysis_error'       => $e->getMessage(),
                'analysis_completed_at'=> now(),
            ]);
        }

        return response()->json([
            'message'         => 'CV re-analysis triggered successfully.',
            'user_id'         => $userId,
            'analysis_status' => $profile->fresh()->analysis_status,
        ]);
    }
}
