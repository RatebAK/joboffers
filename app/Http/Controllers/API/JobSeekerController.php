<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\JobSeekerProfile;
use App\Models\JobPost;
use App\Services\CvAnalysisService;
use App\Exceptions\CvAnalysisException;
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

    // Upload CV and trigger AI analysis
    public function uploadAndAnalyze(Request $request, CvAnalysisService $cvAnalysisService)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'cv' => 'required|file|mimes:pdf,doc,docx|max:10240', // 10 MB max
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        $profile = $user->jobSeekerProfile()->firstOrCreate(['user_id' => $user->_id]);

        // Delete previous CV file if it exists
        if ($profile->cv_file_path) {
            Storage::delete($profile->cv_file_path);
        }

        // Store new file
        $storedPath = $request->file('cv')->store('resumes', 'public');
        $cvFilePath = 'public/resumes/' . basename($storedPath);

        try {
            $analysis = $cvAnalysisService->analyze($cvFilePath);
        } catch (CvAnalysisException $e) {
            // Remove the just-uploaded file since analysis failed
            Storage::delete($cvFilePath);

            $statusCode = $e->getHttpStatusCode();

            if ($statusCode === 422) {
                return response()->json([
                    'message' => 'CV analysis failed',
                    'reason'  => $e->getMessage(),
                ], 422);
            }

            return response()->json([
                'message' => 'CV analysis service unavailable',
            ], 502);
        }

        // Persist all AI fields and cv_file_path
        $profile->update([
            'cv_file_path'          => $cvFilePath,
            'ai_full_name'          => $analysis['full_name'] ?? null,
            'ai_email'              => $analysis['email'] ?? null,
            'ai_phone'              => $analysis['phone'] ?? null,
            'ai_location'           => $analysis['location'] ?? null,
            'ai_summary'            => $analysis['summary'] ?? null,
            'ai_skills'             => $analysis['skills'] ?? null,
            'ai_work_history'       => $analysis['work_history'] ?? null,
            'ai_projects'           => $analysis['projects'] ?? null,
            'ai_overall_evaluation' => $analysis['ai_overall_evaluation'] ?? null,
            'ats_score'             => $analysis['ats_score'] ?? null,
            'ai_detected_language'  => $analysis['detected_language'] ?? null,
            'ai_analyzed_at'        => now(),
        ]);

        return response()->json([
            'profile' => $profile->fresh(),
        ], 200);
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
            'keyword'    => 'nullable|string|max:100',
            'location'   => 'nullable|string|max:100',
            'job_type'   => 'nullable|string|in:full_time,part_time,contract,freelance',
            'category'   => 'nullable|string|max:100',
            'min_salary' => 'nullable|numeric|min:0',
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

        if ($request->min_salary !== null) {
            $query->where('salary_range.min', '>=', (int) $request->min_salary);
        }

        $jobs = $query->orderBy('created_at', 'desc')
                     ->paginate(15);

        return response()->json([
            'jobs' => $jobs
        ]);
    }
}