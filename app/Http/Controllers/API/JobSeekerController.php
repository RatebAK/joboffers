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
        $validator = Validator::make($request->all(), [
            // Personal Information
            'full_name'                     => 'nullable|string|max:100',
            'location'                      => 'nullable|string|max:255',
            'age'                           => 'nullable|integer|min:16|max:100',
            'nationality'                   => 'nullable|string|max:100',
            'gender'                        => 'nullable|string|in:male,female,other,prefer_not_to_say',
            'marital_status'                => 'nullable|string|in:single,married,divorced,widowed,prefer_not_to_say',
            // Contact
            'phone'                         => 'nullable|string|max:20',
            'address'                       => 'nullable|string|max:255',
            // Career Information
            'years_of_experience'           => 'nullable|integer|min:0|max:60',
            'job_level'                     => 'nullable|string|in:entry,junior,mid,senior,lead,manager,director,executive',
            'education_level'               => 'nullable|string|max:100',
            'current_job_status'            => 'nullable|string|in:employed,unemployed,freelancing,student,open_to_work',
            'current_job_title'             => 'nullable|string|max:100',
            'experience_summary'            => 'nullable|string',
            'expected_salary'               => 'nullable|numeric|min:0',
            'is_actively_seeking'           => 'boolean',
            // Social & Portfolio
            'linkedin_url'                  => 'nullable|url|max:255',
            'github_url'                    => 'nullable|url|max:255',
            'portfolio_url'                 => 'nullable|url|max:255',
            'twitter_url'                   => 'nullable|url|max:255',
            // Structured data
            'skills'                        => 'nullable|array',
            'skills.*.name'                 => 'required_with:skills|string|max:50',
            'skills.*.level'                => 'nullable|string|in:beginner,intermediate,advanced,expert',
            'education_history'             => 'nullable|array',
            'education_history.*.degree'    => 'nullable|string|max:100',
            'education_history.*.field'     => 'nullable|string|max:100',
            'education_history.*.school'    => 'nullable|string|max:100',
            'education_history.*.grade'     => 'nullable|string|max:50',
            'education_history.*.start_date'=> 'nullable|string|max:20',
            'education_history.*.end_date'  => 'nullable|string|max:20',
            'work_experience'               => 'nullable|array',
            'work_experience.*.title'       => 'nullable|string|max:100',
            'work_experience.*.company'     => 'nullable|string|max:100',
            'work_experience.*.start_date'  => 'nullable|string|max:20',
            'work_experience.*.end_date'    => 'nullable|string|max:20',
            'work_experience.*.is_current'  => 'nullable|boolean',
            'work_experience.*.tags'        => 'nullable|array',
            'work_experience.*.tags.*'      => 'string|max:50',
            'work_experience.*.description' => 'nullable|string',
            // Resume file
            'resume'                        => 'nullable|file|mimes:pdf,doc,docx|max:5120',
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