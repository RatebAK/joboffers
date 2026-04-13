<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\JobSeekerProfile;
use App\Models\Application;
use App\Models\JobPost;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use MongoDB\BSON\ObjectId;

class JobSeekerController extends Controller
{
    // Get job seeker profile
    public function show(Request $request)
    {
        $user = $request->user();
        
        if ($user->is_employer) {
            return response()->json([
                'message' => 'Employers cannot access job seeker profiles'
            ], 403);
        }

        $profile = $user->jobSeekerProfile;
        
        if (!$profile) {
            // Create empty profile if it doesn't exist
            $profile = $user->jobSeekerProfile()->create([]);
        }

        return response()->json([
            'profile' => $profile
        ]);
    }

    // Create or update job seeker profile
    public function update(Request $request)
    {
        $user = $request->user();

        if ($user->is_employer) {
            return response()->json([
                'message' => 'Employers cannot update job seeker profiles'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'resume' => 'nullable|file|mimes:pdf,doc,docx|max:5120', // 5MB max
            'skills' => 'nullable|array',
            'skills.*' => 'string|max:50',
            'education_level' => 'nullable|string|max:100',
            'experience_summary' => 'nullable|string',
            'current_job_title' => 'nullable|string|max:100',
            'expected_salary' => 'nullable|numeric|min:0',
            'linkedin_url' => 'nullable|url|max:255',
            'portfolio_url' => 'nullable|url|max:255',
            'is_actively_seeking' => 'boolean',
            'education_history' => 'nullable|array',
            'work_experience' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();

        // Handle resume file upload
        if ($request->hasFile('resume')) {
            // Delete old resume if exists
            if ($user->jobSeekerProfile && $user->jobSeekerProfile->resume) {
                Storage::delete($user->jobSeekerProfile->resume);
            }
            
            $resumePath = $request->file('resume')->store('resumes', 'public');
            $data['resume'] = $resumePath;
        }

        // Update or create profile
        $profile = $user->jobSeekerProfile()->updateOrCreate(
            ['user_id' => $user->_id],
            $data
        );

        return response()->json([
            'message' => 'Profile updated successfully',
            'profile' => $profile
        ]);
    }

    // Get job seeker's applications
    public function applications(Request $request)
    {
        $user = $request->user();

        if ($user->is_employer) {
            return response()->json([
                'message' => 'Employers cannot access job seeker applications'
            ], 403);
        }

        $applications = Application::with('jobPost')
            ->where('user_id', $user->_id)
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return response()->json([
            'applications' => $applications
        ]);
    }

    // Apply to a job
    public function apply(Request $request)
    {
        $user = $request->user();

        if ($user->is_employer) {
            return response()->json([
                'message' => 'Employers cannot apply to jobs'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'job_post_id' => 'required|string',
            'cover_letter' => 'nullable|string|max:1000',
            'resume' => 'nullable|file|mimes:pdf,doc,docx|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();

        // Check if job post exists
        try {
            $jobPost = JobPost::findOrFail($data['job_post_id']);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Job post not found'
            ], 404);
        }

        // Check if user already applied to this job
        $existingApplication = Application::where('user_id', $user->_id)
            ->where('job_post_id', $data['job_post_id'])
            ->first();

        if ($existingApplication) {
            return response()->json([
                'message' => 'You have already applied to this job'
            ], 409);
        }

        // Handle resume file upload for this specific application
        if ($request->hasFile('resume')) {
            $resumePath = $request->file('resume')->store('application_resumes', 'public');
            $data['resume'] = $resumePath;
        } else {
            // Use profile resume if no new resume provided
            $data['resume'] = $user->jobSeekerProfile->resume ?? null;
        }

        // Create application
        $application = Application::create([
            'user_id' => $user->_id,
            'job_post_id' => $data['job_post_id'],
            'cover_letter' => $data['cover_letter'] ?? null,
            'resume' => $data['resume'],
            'status' => 'pending',
            'applied_at' => now(),
        ]);

        return response()->json([
            'message' => 'Application submitted successfully',
            'application' => $application->load('jobPost')
        ], 201);
    }

    // Withdraw application
    public function withdrawApplication(Request $request, $applicationId)
    {
        $user = $request->user();

        if ($user->is_employer) {
            return response()->json([
                'message' => 'Employers cannot withdraw applications'
            ], 403);
        }

        try {
            $application = Application::where('user_id', $user->_id)
                ->where('_id', $applicationId)
                ->firstOrFail();
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Application not found'
            ], 404);
        }

        // Don't allow withdrawal if already accepted
        if ($application->status === 'accepted') {
            return response()->json([
                'message' => 'Cannot withdraw an accepted application'
            ], 403);
        }

        $application->delete();

        return response()->json([
            'message' => 'Application withdrawn successfully'
        ]);
    }

    // Upload resume separately
    public function uploadResume(Request $request)
    {
        $user = $request->user();

        if ($user->is_employer) {
            return response()->json([
                'message' => 'Employers cannot upload resumes'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'resume' => 'required|file|mimes:pdf,doc,docx|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        // Ensure user has a job seeker profile
        $profile = $user->jobSeekerProfile()->firstOrCreate([
            'user_id' => $user->_id
        ]);

        // Delete old resume if exists
        if ($profile->resume) {
            Storage::delete($profile->resume);
        }

        $resumePath = $request->file('resume')->store('resumes', 'public');
        $profile->update(['resume' => $resumePath]);

        return response()->json([
            'message' => 'Resume uploaded successfully',
            'resume_url' => Storage::url($resumePath)
        ]);
    }

    // Search job posts
    public function searchJobs(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'keyword' => 'nullable|string|max:100',
            'location' => 'nullable|string|max:100',
            'job_type' => 'nullable|string|in:full_time,part_time,contract,freelance',
            'category' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        $query = JobPost::where('is_active', true);

        if ($request->keyword) {
            $query->where(function ($q) use ($request) {
                $q->where('title', 'like', "%{$request->keyword}%")
                  ->orWhere('description', 'like', "%{$request->keyword}%")
                  ->orWhere('company_name', 'like', "%{$request->keyword}%");
            });
        }

        if ($request->location) {
            $query->where('location', 'like', "%{$request->location}%");
        }

        if ($request->job_type) {
            $query->where('job_type', $request->job_type);
        }

        if ($request->category) {
            $query->where('category', $request->category);
        }

        $jobs = $query->orderBy('created_at', 'desc')
                     ->paginate(15);

        return response()->json([
            'jobs' => $jobs
        ]);
    }
}