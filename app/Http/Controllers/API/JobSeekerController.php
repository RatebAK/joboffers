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
    /**
     * Get job seeker profile
     *
     * Returns the authenticated job seeker's profile. Creates an empty profile if one doesn't exist yet.
     *
     * @response 200 {
     *   "profile": {
     *     "id": "664f1a2b3c4d5e6f7a8b9c0d",
     *     "user_id": "664f1a2b3c4d5e6f7a8b9c0e",
     *     "full_name": "Jane Smith",
     *     "current_job_title": "Frontend Developer",
     *     "is_actively_seeking": true,
     *     "skills": [{ "name": "React", "level": "advanced" }],
     *     "ats_score": 82,
     *     "ai_skills": ["React", "TypeScript"]
     *   }
     * }
     */
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

    /**
     * Update job seeker profile
     *
     * Creates or updates the authenticated job seeker's profile fields. All fields are optional — only provided fields are updated.
     *
     * @bodyParam first_name string Example: Jane
     * @bodyParam last_name string Example: Smith
     * @bodyParam full_name string Example: Jane Smith
     * @bodyParam image string URL to profile photo. Example: https://example.com/photo.jpg
     * @bodyParam gender string One of: male, female, other, prefer_not_to_say. Example: female
     * @bodyParam nationality string Example: Lebanese
     * @bodyParam city string Example: Beirut
     * @bodyParam location string Example: Beirut, Lebanon
     * @bodyParam phone string Max 20 chars. Example: +961 70 123456
     * @bodyParam date_of_birth string Example: 1995-06-15
     * @bodyParam marital_status string One of: single, married, divorced, widowed, prefer_not_to_say.
     * @bodyParam salary_range_from number Example: 2000
     * @bodyParam salary_range_to number Example: 5000
     * @bodyParam current_job_status string One of: employed, unemployed, freelancing, student, open_to_work.
     * @bodyParam years_of_experience integer Example: 5
     * @bodyParam education_level string Example: Bachelor's Degree
     * @bodyParam job_level string One of: entry, junior, mid, senior, lead, manager, director, executive.
     * @bodyParam job_types string[] Example: ["full_time","remote"]
     * @bodyParam job_roles string[] Example: ["Frontend","React"]
     * @bodyParam work_cities string[] Example: ["Beirut","Dubai"]
     * @bodyParam current_job_title string Max 100 chars. Example: Frontend Developer
     * @bodyParam experience_summary string
     * @bodyParam expected_salary number Example: 3500
     * @bodyParam is_actively_seeking boolean Example: true
     * @bodyParam social_links object Social media URLs.
     * @bodyParam social_links.linkedin string Example: https://linkedin.com/in/janesmith
     * @bodyParam social_links.github string Example: https://github.com/janesmith
     * @bodyParam social_links.portfolio string Example: https://janesmith.dev
     * @bodyParam social_links.twitter string
     * @bodyParam skills object[] Array of skill objects.
     * @bodyParam skills[].name string required Example: React
     * @bodyParam skills[].level string One of: beginner, intermediate, advanced, expert. Example: advanced
     * @bodyParam education_history object[] Array of education entries.
     * @bodyParam work_experience object[] Array of work experience entries.
     * @bodyParam resume file PDF/DOC resume file (max 5 MB).
     *
     * @response 200 {
     *   "message": "Profile updated successfully",
     *   "profile": { "id": "664f1a2b3c4d5e6f7a8b9c0d", "full_name": "Jane Smith", "is_actively_seeking": true }
     * }
     * @response 422 { "errors": { "phone": ["Must not be greater than 20 characters."] } }
     */
    // Create or update job seeker profile
    public function update(Request $request)
    {
        $user = $request->user();
        $validator = Validator::make($request->all(), [
            // Personal Information
            'first_name'                              => 'nullable|string|max:50',
            'last_name'                               => 'nullable|string|max:50',
            'full_name'                               => 'nullable|string|max:100',
            'image'                                   => 'nullable|url|max:500',
            'gender'                                  => 'nullable|string|in:male,female,other,prefer_not_to_say',
            'nationality'                             => 'nullable|string|max:100',
            'city'                                    => 'nullable|string|max:100',
            'location'                                => 'nullable|string|max:255',
            'address'                                 => 'nullable|string|max:255',
            'phone'                                   => 'nullable|string|max:20',
            'date_of_birth'                           => 'nullable|string|max:20',
            'marital_status'                          => 'nullable|string|in:single,married,divorced,widowed,prefer_not_to_say',
            // Career Information
            'salary_range_from'                       => 'nullable|numeric|min:0',
            'salary_range_to'                         => 'nullable|numeric|min:0',
            'current_job_status'                      => 'nullable|string|in:employed,unemployed,freelancing,student,open_to_work',
            'years_of_experience'                     => 'nullable|integer|min:0|max:60',
            'education_level'                         => 'nullable|string|max:100',
            'job_level'                               => 'nullable|string|in:entry,junior,mid,senior,lead,manager,director,executive',
            'job_types'                               => 'nullable|array',
            'job_types.*'                             => 'string|max:50',
            'job_roles'                               => 'nullable|array',
            'job_roles.*'                             => 'string|max:50',
            'work_cities'                             => 'nullable|array',
            'work_cities.*'                           => 'string|max:100',
            'current_job_title'                       => 'nullable|string|max:100',
            'experience_summary'                      => 'nullable|string',
            'expected_salary'                         => 'nullable|numeric|min:0',
            'is_actively_seeking'                     => 'boolean',
            // Social Links (nested object)
            'social_links'                            => 'nullable|array',
            'social_links.linkedin'                   => 'nullable|url|max:255',
            'social_links.github'                     => 'nullable|url|max:255',
            'social_links.portfolio'                  => 'nullable|url|max:255',
            'social_links.twitter'                    => 'nullable|url|max:255',
            // Skills
            'skills'                                  => 'nullable|array',
            'skills.*.name'                           => 'required_with:skills|string|max:50',
            'skills.*.level'                          => 'nullable|string|in:beginner,intermediate,advanced,expert',
            // Education History
            'education_history'                       => 'nullable|array',
            'education_history.*.certificate_type'    => 'nullable|string|max:100',
            'education_history.*.university'          => 'nullable|string|max:150',
            'education_history.*.faculty'             => 'nullable|string|max:150',
            'education_history.*.major'               => 'nullable|string|max:100',
            'education_history.*.major_name'          => 'nullable|string|max:150',
            'education_history.*.grade'               => 'nullable|string|max:50',
            'education_history.*.from_date'           => 'nullable|string|max:20',
            'education_history.*.awarded_date'        => 'nullable|string|max:20',
            // Work Experience
            'work_experience'                         => 'nullable|array',
            'work_experience.*.job_title'             => 'nullable|string|max:100',
            'work_experience.*.company_name'          => 'nullable|string|max:100',
            'work_experience.*.job_roles'             => 'nullable|array',
            'work_experience.*.job_roles.*'           => 'string|max:50',
            'work_experience.*.from_date'             => 'nullable|string|max:20',
            'work_experience.*.to_date'               => 'nullable|string|max:20',
            'work_experience.*.is_currently_working'  => 'nullable|boolean',
            'work_experience.*.description'           => 'nullable|string',
            // Resume file
            'resume'                                  => 'nullable|file|mimes:pdf,doc,docx|max:5120',
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

    /**
     * Upload and analyze CV
     *
     * Uploads a CV file and sends it to the AI analysis service. On success, populates all `ai_*` profile fields and `ats_score`.
     *
     * @bodyParam cv file required PDF/DOC/DOCX file, max 10 MB.
     *
     * @response 200 {
     *   "profile": {
     *     "id": "664f1a2b3c4d5e6f7a8b9c0d",
     *     "ats_score": 82,
     *     "ai_skills": ["React", "TypeScript", "Node.js"],
     *     "ai_summary": "Experienced frontend developer...",
     *     "ai_work_history": [],
     *     "ai_analyzed_at": "2024-01-15T00:00:00Z"
     *   }
     * }
     * @response 422 { "message": "CV analysis failed", "reason": "CV content could not be parsed." }
     * @response 502 { "message": "CV analysis service unavailable" }
     */
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

    /**
     * Upload resume
     *
     * Uploads a resume file to the job seeker's profile without triggering AI analysis.
     *
     * @bodyParam resume file required PDF/DOC/DOCX file, max 5 MB.
     *
     * @response 200 {
     *   "message": "Resume uploaded successfully",
     *   "resume_url": "https://example.com/storage/resumes/cv.pdf"
     * }
     * @response 422 { "errors": { "resume": ["The resume field is required."] } }
     */
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

    /**
     * Search jobs
     *
     * Paginated search of active job posts for the authenticated job seeker.
     *
     * @bodyParam keyword string Search in title, description, company name. Max 100 chars. Example: React
     * @bodyParam location string Partial location match. Max 100 chars. Example: Beirut
     * @bodyParam job_type string One of: full_time, part_time, contract, freelance. Example: full_time
     * @bodyParam category string Example: Engineering
     * @bodyParam min_salary number Minimum salary. Example: 2000
     *
     * @response 200 {
     *   "jobs": {
     *     "data": [{ "id": "664f1a2b3c4d5e6f7a8b9c0d", "title": "Senior React Developer", "company_name": "Acme Corp" }],
     *     "current_page": 1, "per_page": 15, "total": 1, "total_pages": 1, "next_page": null, "prev_page": null
     *   }
     * }
     */
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