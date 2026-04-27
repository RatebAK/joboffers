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
     * Job seeker: paginated list of their applications with job post title and company name.
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
     * Job seeker: apply to a job post.
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
     * Job seeker: withdraw a pending application.
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

        $application->delete();

        return response()->json(['message' => 'Application withdrawn successfully']);
    }

    /**
     * Employer: list applications for a job post they own.
     * Includes applicant name and ats_score.
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
     * Employer: update application status and optional feedback.
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
