<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\JobPost;
use App\Models\JobSeekerProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ApplicationController extends Controller
{
    /**
     * My applications
     *
     * Paginated list of the authenticated job seeker's applications, each including the related job post.
     *
     * @response 200 {
     *   "applications": {
     *     "data": [
     *       {
     *         "id": "664f1a2b3c4d5e6f7a8b9c0d",
     *         "job_post_id": "664f1a2b3c4d5e6f7a8b9c0e",
     *         "status": "pending",
     *         "cover_letter": "I am very interested...",
     *         "applied_at": "2024-01-15T00:00:00Z",
     *         "job_post": { "title": "Senior Laravel Developer", "company_name": "Acme Corp" }
     *       }
     *     ],
     *     "current_page": 1, "per_page": 10, "total": 1, "total_pages": 1, "next_page": null, "prev_page": null
     *   }
     * }
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $applications = Application::with('jobPost')
            ->where('user_id', $user->_id)
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return response()->json([
            'applications' => $applications
        ]);
    }

    /**
     * Apply to job
     *
     * Submit an application for an active job post. If no resume file is uploaded, the profile's stored CV is used automatically.
     *
     * @bodyParam job_post_id string required The ID of the job post to apply to. Example: 664f1a2b3c4d5e6f7a8b9c0d
     * @bodyParam cover_letter string Optional cover letter. Max 1000 chars. Example: I am very interested in this position.
     * @bodyParam resume file Optional PDF/DOC resume file (max 5 MB). Overrides profile CV.
     *
     * @response 201 {
     *   "message": "Application submitted successfully",
     *   "application": {
     *     "id": "664f1a2b3c4d5e6f7a8b9c0f",
     *     "job_post_id": "664f1a2b3c4d5e6f7a8b9c0d",
     *     "status": "pending",
     *     "applied_at": "2024-01-15T00:00:00Z"
     *   }
     * }
     * @response 404 { "message": "Job post not found or is not active" }
     * @response 409 { "message": "You have already applied to this job" }
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'job_post_id'  => 'required|string',
            'cover_letter' => 'nullable|string|max:1000',
            'resume'       => 'nullable|file|mimes:pdf,doc,docx|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Check job post exists and is active
        try {
            $jobPost = JobPost::where('_id', $data['job_post_id'])
                ->where('is_active', true)
                ->firstOrFail();
        } catch (\Exception $e) {
            return response()->json(['message' => 'Job post not found or is not active'], 404);
        }

        // Check for duplicate application
        $existing = Application::where('user_id', $user->_id)
            ->where('job_post_id', $data['job_post_id'])
            ->first();

        if ($existing) {
            return response()->json(['message' => 'You have already applied to this job'], 409);
        }

        // Resolve resume: uploaded file > profile cv_file_path
        if ($request->hasFile('resume')) {
            $data['resume'] = $request->file('resume')->store('application_resumes', 'public');
        } else {
            $profile = $user->jobSeekerProfile;
            $data['resume'] = $profile->cv_file_path ?? null;
        }

        $application = Application::create([
            'user_id'      => $user->_id,
            'job_post_id'  => $data['job_post_id'],
            'cover_letter' => $data['cover_letter'] ?? null,
            'resume'       => $data['resume'],
            'status'       => 'pending',
            'applied_at'   => now(),
        ]);

        return response()->json([
            'message'     => 'Application submitted successfully',
            'application' => $application->load('jobPost'),
        ], 201);
    }

    /**
     * Withdraw application
     *
     * Withdraws (deletes) a pending application. Accepted applications cannot be withdrawn.
     *
     * @urlParam id string required The application ID. Example: 664f1a2b3c4d5e6f7a8b9c0f
     *
     * @response 200 { "message": "Application withdrawn successfully" }
     * @response 403 { "message": "Cannot withdraw an accepted application" }
     * @response 404 { "message": "Application not found" }
     */
    public function withdraw(Request $request, $id)
    {
        $user = $request->user();

        try {
            $application = Application::where('user_id', $user->_id)
                ->where('_id', $id)
                ->firstOrFail();
        } catch (\Exception $e) {
            return response()->json(['message' => 'Application not found'], 404);
        }

        if ($application->status === 'accepted') {
            return response()->json(['message' => 'Cannot withdraw an accepted application'], 403);
        }

        if ($application->status === 'rejected') {
            return response()->json(['message' => 'Cannot withdraw a rejected application'], 403);
        }

        $application->delete();

        return response()->json(['message' => 'Application withdrawn successfully']);
    }

    /**
     * Job applications (employer)
     *
     * Paginated list of applications for a specific job post owned by the authenticated employer. Includes applicant name and ATS score.
     *
     * @urlParam jobId string required The job post ID. Example: 664f1a2b3c4d5e6f7a8b9c0d
     *
     * @response 200 {
     *   "applications": {
     *     "data": [
     *       {
     *         "id": "664f1a2b3c4d5e6f7a8b9c0f",
     *         "user_id": "664f1a2b3c4d5e6f7a8b9c0e",
     *         "status": "pending",
     *         "cover_letter": "I am very interested...",
     *         "applied_at": "2024-01-15T00:00:00Z",
     *         "applicant_name": "Jane Smith",
     *         "ats_score": 82
     *       }
     *     ],
     *     "current_page": 1, "per_page": 15, "total": 1, "total_pages": 1, "next_page": null, "prev_page": null
     *   }
     * }
     * @response 403 { "message": "Forbidden" }
     * @response 404 { "message": "Job post not found" }
     */
    public function indexForEmployer(Request $request, $jobId)
    {
        $user = $request->user();

        try {
            $jobPost = JobPost::findOrFail($jobId);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Job post not found'], 404);
        }

        if ((string) $jobPost->employer_id !== (string) $user->_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $applications = Application::with('user')
            ->where('job_post_id', $jobId)
            ->orderBy('created_at', 'desc')
            ->paginate(15)
            ->through(function ($application) {
                $profile = JobSeekerProfile::where('user_id', $application->user_id)->first();

                return array_merge($application->toArray(), [
                    'applicant_name' => $application->user->name ?? null,
                    'ats_score'      => $profile->ats_score ?? null,
                ]);
            });

        return response()->json(['applications' => $applications]);
    }

    /**
     * Update application status
     *
     * Updates the status and optional feedback on an application. Only the employer who owns the related job post can do this.
     *
     * @urlParam id string required The application ID. Example: 664f1a2b3c4d5e6f7a8b9c0f
     * @bodyParam status string required One of: pending, reviewed, accepted, rejected. Example: accepted
     * @bodyParam feedback string Optional feedback message. Max 2000 chars. Example: Great profile, moving forward.
     *
     * @response 200 {
     *   "message": "Application status updated successfully",
     *   "application": { "id": "664f1a2b3c4d5e6f7a8b9c0f", "status": "accepted", "feedback": "Great profile." }
     * }
     * @response 403 { "message": "Forbidden" }
     * @response 404 { "message": "Application not found" }
     */
    public function updateStatus(Request $request, $id)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'status'   => 'required|string|in:pending,reviewed,accepted,rejected',
            'feedback' => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $application = Application::with('jobPost')->findOrFail($id);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Application not found'], 404);
        }

        // Verify the employer owns the job post
        if ((string) $application->jobPost->employer_id !== (string) $user->_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $application->update([
            'status'   => $request->status,
            'feedback' => $request->feedback ?? $application->feedback,
        ]);

        return response()->json([
            'message'     => 'Application status updated successfully',
            'application' => $application->fresh(),
        ]);
    }
}
