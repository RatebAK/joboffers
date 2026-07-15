<?php

namespace App\Http\Controllers\API;

use App\Exceptions\CvAnalysisException;
use App\Http\Controllers\Controller;
use App\Models\JobPost;
use App\Services\CvAnalysisService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

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

        if (! $profile) {
            $profile = $user->jobSeekerProfile()->create([]);
        }

        return response()->json([
            'profile'   => $profile,
            'documents' => [
                'cv_url'               => $profile->cv_file_path,
                'cv_analyzed_at'       => $profile->ai_analyzed_at,
                'resume_url'           => $profile->resume,
                'default_cover_letter' => $profile->default_cover_letter,
            ],
        ]);
    }

    /**
     * Update personal information
     *
     * Updates the authenticated job seeker's personal information fields.
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
     *
     * @response 200 {
     *   "message": "Personal information updated successfully",
     *   "profile": { "id": "664f1a2b3c4d5e6f7a8b9c0d", "full_name": "Jane Smith" }
     * }
     */
    public function updatePersonalInfo(Request $request)
    {
        $user = $request->user();
        $validator = Validator::make($request->all(), [
            'first_name' => 'nullable|string|max:50',
            'last_name' => 'nullable|string|max:50',
            'full_name' => 'nullable|string|max:100',
            'image' => 'nullable|url|max:500',
            'gender' => 'nullable|string|in:male,female,other,prefer_not_to_say',
            'nationality' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'location' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'date_of_birth' => 'nullable|string|max:20',
            'marital_status' => 'nullable|string|in:single,married,divorced,widowed,prefer_not_to_say',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $profile = $user->jobSeekerProfile()->updateOrCreate(
            ['user_id' => $user->_id],
            $validator->validated()
        );

        return response()->json([
            'message' => 'Personal information updated successfully',
            'profile' => $profile,
        ]);
    }

    /**
     * Update career information
     *
     * Updates the authenticated job seeker's career-related information.
     *
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
     *
     * @response 200 {
     *   "message": "Career information updated successfully",
     *   "profile": { "id": "664f1a2b3c4d5e6f7a8b9c0d", "current_job_title": "Frontend Developer" }
     * }
     */
    public function updateCareerInfo(Request $request)
    {
        $user = $request->user();
        $validator = Validator::make($request->all(), [
            'salary_range_from' => 'nullable|numeric|min:0',
            'salary_range_to' => 'nullable|numeric|min:0',
            'current_job_status' => 'nullable|string|in:employed,unemployed,freelancing,student,open_to_work',
            'years_of_experience' => 'nullable|integer|min:0|max:60',
            'education_level' => 'nullable|string|max:100',
            'job_level' => 'nullable|string|in:entry,junior,mid,senior,lead,manager,director,executive',
            'job_types' => 'nullable|array',
            'job_types.*' => 'string|max:50',
            'job_roles' => 'nullable|array',
            'job_roles.*' => 'string|max:50',
            'work_cities' => 'nullable|array',
            'work_cities.*' => 'string|max:100',
            'current_job_title' => 'nullable|string|max:100',
            'experience_summary' => 'nullable|string',
            'expected_salary' => 'nullable|numeric|min:0',
            'is_actively_seeking' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $profile = $user->jobSeekerProfile()->updateOrCreate(
            ['user_id' => $user->_id],
            $validator->validated()
        );

        return response()->json([
            'message' => 'Career information updated successfully',
            'profile' => $profile,
        ]);
    }

    /**
     * Update social links
     *
     * Updates the authenticated job seeker's social media links.
     *
     * @bodyParam social_links object Social media URLs.
     * @bodyParam social_links.linkedin string Example: https://linkedin.com/in/janesmith
     * @bodyParam social_links.github string Example: https://github.com/janesmith
     * @bodyParam social_links.portfolio string Example: https://janesmith.dev
     * @bodyParam social_links.twitter string
     *
     * @response 200 {
     *   "message": "Social links updated successfully",
     *   "profile": { "social_links": { "linkedin": "https://linkedin.com/in/janesmith" } }
     * }
     */
    public function updateSocialLinks(Request $request)
    {
        $user = $request->user();
        $validator = Validator::make($request->all(), [
            'social_links' => 'required|array',
            'social_links.linkedin' => 'nullable|url|max:255',
            'social_links.github' => 'nullable|url|max:255',
            'social_links.portfolio' => 'nullable|url|max:255',
            'social_links.twitter' => 'nullable|url|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $profile = $user->jobSeekerProfile()->updateOrCreate(
            ['user_id' => $user->_id],
            $validator->validated()
        );

        return response()->json([
            'message' => 'Social links updated successfully',
            'profile' => $profile,
        ]);
    }

    /**
     * Update skills
     *
     * Replaces all skills with the provided array.
     *
     * @bodyParam skills object[] required Array of skill objects.
     * @bodyParam skills[].name string required Example: React
     * @bodyParam skills[].level string One of: beginner, intermediate, advanced, expert. Example: advanced
     *
     * @response 200 {
     *   "message": "Skills updated successfully",
     *   "profile": { "skills": [{ "name": "React", "level": "advanced" }] }
     * }
     */
    public function updateSkills(Request $request)
    {
        $user = $request->user();
        $validator = Validator::make($request->all(), [
            'skills' => 'required|array',
            'skills.*.name' => 'required|string|max:50',
            'skills.*.level' => 'nullable|string|in:beginner,intermediate,advanced,expert',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $profile = $user->jobSeekerProfile()->updateOrCreate(
            ['user_id' => $user->_id],
            $validator->validated()
        );

        return response()->json([
            'message' => 'Skills updated successfully',
            'profile' => $profile,
        ]);
    }

    /**
     * Delete skills
     *
     * Removes all skills from the profile.
     *
     * @response 200 { "message": "Skills deleted successfully" }
     */
    public function deleteSkills(Request $request)
    {
        $user = $request->user();
        $profile = $user->jobSeekerProfile;

        if ($profile) {
            $profile->update(['skills' => []]);
        }

        return response()->json(['message' => 'Skills deleted successfully']);
    }

    /**
     * Update education history
     *
     * Replaces all education entries with the provided array.
     *
     * @bodyParam education_history object[] required Array of education entries.
     * @bodyParam education_history[].certificate_type string Example: Bachelor's Degree
     * @bodyParam education_history[].university string Example: American University of Beirut
     * @bodyParam education_history[].faculty string Example: Engineering
     * @bodyParam education_history[].major string Example: Computer Science
     * @bodyParam education_history[].grade string Example: 3.8 GPA
     * @bodyParam education_history[].from_date string Example: 2015-09
     * @bodyParam education_history[].awarded_date string Example: 2019-06
     *
     * @response 200 {
     *   "message": "Education history updated successfully",
     *   "profile": { "education_history": [{ "university": "AUB", "major": "CS" }] }
     * }
     */
    public function updateEducation(Request $request)
    {
        $user = $request->user();
        $validator = Validator::make($request->all(), [
            'education_history' => 'required|array',
            'education_history.*.certificate_type' => 'nullable|string|max:100',
            'education_history.*.university' => 'nullable|string|max:150',
            'education_history.*.faculty' => 'nullable|string|max:150',
            'education_history.*.major' => 'nullable|string|max:100',
            'education_history.*.major_name' => 'nullable|string|max:150',
            'education_history.*.grade' => 'nullable|string|max:50',
            'education_history.*.from_date' => 'nullable|string|max:20',
            'education_history.*.awarded_date' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $profile = $user->jobSeekerProfile()->updateOrCreate(
            ['user_id' => $user->_id],
            $validator->validated()
        );

        return response()->json([
            'message' => 'Education history updated successfully',
            'profile' => $profile,
        ]);
    }

    /**
     * Delete education history
     *
     * Removes all education entries from the profile.
     *
     * @response 200 { "message": "Education history deleted successfully" }
     */
    public function deleteEducation(Request $request)
    {
        $user = $request->user();
        $profile = $user->jobSeekerProfile;

        if ($profile) {
            $profile->update(['education_history' => []]);
        }

        return response()->json(['message' => 'Education history deleted successfully']);
    }

    /**
     * Update work experience
     *
     * Replaces all work experience entries with the provided array.
     *
     * @bodyParam work_experience object[] required Array of work experience entries.
     * @bodyParam work_experience[].job_title string Example: Frontend Developer
     * @bodyParam work_experience[].company_name string Example: Acme Corp
     * @bodyParam work_experience[].job_roles string[] Example: ["React","TypeScript"]
     * @bodyParam work_experience[].from_date string Example: 2020-01
     * @bodyParam work_experience[].to_date string Example: 2023-06
     * @bodyParam work_experience[].is_currently_working boolean Example: false
     * @bodyParam work_experience[].description string
     *
     * @response 200 {
     *   "message": "Work experience updated successfully",
     *   "profile": { "work_experience": [{ "job_title": "Developer", "company_name": "Acme" }] }
     * }
     */
    public function updateWorkExperience(Request $request)
    {
        $user = $request->user();
        $validator = Validator::make($request->all(), [
            'work_experience' => 'required|array',
            'work_experience.*.job_title' => 'nullable|string|max:100',
            'work_experience.*.company_name' => 'nullable|string|max:100',
            'work_experience.*.job_roles' => 'nullable|array',
            'work_experience.*.job_roles.*' => 'string|max:50',
            'work_experience.*.from_date' => 'nullable|string|max:20',
            'work_experience.*.to_date' => 'nullable|string|max:20',
            'work_experience.*.is_currently_working' => 'nullable|boolean',
            'work_experience.*.description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $profile = $user->jobSeekerProfile()->updateOrCreate(
            ['user_id' => $user->_id],
            $validator->validated()
        );

        return response()->json([
            'message' => 'Work experience updated successfully',
            'profile' => $profile,
        ]);
    }

    /**
     * Delete work experience
     *
     * Removes all work experience entries from the profile.
     *
     * @response 200 { "message": "Work experience deleted successfully" }
     */
    public function deleteWorkExperience(Request $request)
    {
        $user = $request->user();
        $profile = $user->jobSeekerProfile;

        if ($profile) {
            $profile->update(['work_experience' => []]);
        }

        return response()->json(['message' => 'Work experience deleted successfully']);
    }

    /**
     * Legacy update method - DEPRECATED
     * 
     * @deprecated Use specific update endpoints instead
     */
    public function update(Request $request)
    {
        $user = $request->user();
        $validator = Validator::make($request->all(), [
            // Personal Information
            'first_name' => 'nullable|string|max:50',
            'last_name' => 'nullable|string|max:50',
            'full_name' => 'nullable|string|max:100',
            'image' => 'nullable|url|max:500',
            'gender' => 'nullable|string|in:male,female,other,prefer_not_to_say',
            'nationality' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'location' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'date_of_birth' => 'nullable|string|max:20',
            'marital_status' => 'nullable|string|in:single,married,divorced,widowed,prefer_not_to_say',
            // Career Information
            'salary_range_from' => 'nullable|numeric|min:0',
            'salary_range_to' => 'nullable|numeric|min:0',
            'current_job_status' => 'nullable|string|in:employed,unemployed,freelancing,student,open_to_work',
            'years_of_experience' => 'nullable|integer|min:0|max:60',
            'education_level' => 'nullable|string|max:100',
            'job_level' => 'nullable|string|in:entry,junior,mid,senior,lead,manager,director,executive',
            'job_types' => 'nullable|array',
            'job_types.*' => 'string|max:50',
            'job_roles' => 'nullable|array',
            'job_roles.*' => 'string|max:50',
            'work_cities' => 'nullable|array',
            'work_cities.*' => 'string|max:100',
            'current_job_title' => 'nullable|string|max:100',
            'experience_summary' => 'nullable|string',
            'expected_salary' => 'nullable|numeric|min:0',
            'is_actively_seeking' => 'boolean',
            // Social Links (nested object)
            'social_links' => 'nullable|array',
            'social_links.linkedin' => 'nullable|url|max:255',
            'social_links.github' => 'nullable|url|max:255',
            'social_links.portfolio' => 'nullable|url|max:255',
            'social_links.twitter' => 'nullable|url|max:255',
            // Skills
            'skills' => 'nullable|array',
            'skills.*.name' => 'required_with:skills|string|max:50',
            'skills.*.level' => 'nullable|string|in:beginner,intermediate,advanced,expert',
            // Education History
            'education_history' => 'nullable|array',
            'education_history.*.certificate_type' => 'nullable|string|max:100',
            'education_history.*.university' => 'nullable|string|max:150',
            'education_history.*.faculty' => 'nullable|string|max:150',
            'education_history.*.major' => 'nullable|string|max:100',
            'education_history.*.major_name' => 'nullable|string|max:150',
            'education_history.*.grade' => 'nullable|string|max:50',
            'education_history.*.from_date' => 'nullable|string|max:20',
            'education_history.*.awarded_date' => 'nullable|string|max:20',
            // Work Experience
            'work_experience' => 'nullable|array',
            'work_experience.*.job_title' => 'nullable|string|max:100',
            'work_experience.*.company_name' => 'nullable|string|max:100',
            'work_experience.*.job_roles' => 'nullable|array',
            'work_experience.*.job_roles.*' => 'string|max:50',
            'work_experience.*.from_date' => 'nullable|string|max:20',
            'work_experience.*.to_date' => 'nullable|string|max:20',
            'work_experience.*.is_currently_working' => 'nullable|boolean',
            'work_experience.*.description' => 'nullable|string',
            // Resume file
            'resume' => 'nullable|file|mimes:pdf,doc,docx|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
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
            'profile' => $profile,
        ]);
    }

    /**
     * Upload and analyze CV
     *
     * Uploads a CV file and sends it to the AI analysis service. On success, populates all `ai_*` profile fields and `ats_score`.
     * This endpoint is similar to `/upload` but accepts field name `cv` instead of `resume`.
     *
     * @bodyParam cv file required PDF/DOC/DOCX file, max 10 MB.
     *
     * @response 200 {
     *   "message": "CV uploaded and analyzed successfully",
     *   "resume_url": "https://res.cloudinary.com/.../cv.pdf",
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
        // Force PHP to allow up to 2 minutes for this specific execution thread
        set_time_limit(240); //EDITED BY RATEBBBBB

        
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'cv' => 'required|file|mimes:pdf,doc,docx|max:10240', // 10 MB max
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        $profile = $user->jobSeekerProfile()->firstOrCreate(['user_id' => $user->_id]);

        // Log start of CV upload process
        Log::info('Starting CV upload-and-analyze process', [
            'user_id' => $user->_id,
            'endpoint' => 'upload-and-analyze',
            'profile_exists' => $profile->exists,
            'existing_resume' => $profile->resume_public_id ? 'yes' : 'no',
            'existing_cv' => $profile->cv_public_id ? 'yes' : 'no',
        ]);

        // Delete previous resume/CV from Cloudinary if they exist
        if ($profile->resume_public_id) {
            Log::info('Deleting previous resume from Cloudinary (upload-and-analyze)', [
                'user_id' => $user->_id,
                'public_id' => $profile->resume_public_id,
            ]);
            Storage::disk('cloudinary')->delete($profile->resume_public_id);
        }
        // Also delete previous CV if it exists in separate field
        if ($profile->cv_public_id && $profile->cv_public_id !== $profile->resume_public_id) {
            Log::info('Deleting previous CV from Cloudinary (upload-and-analyze)', [
                'user_id' => $user->_id,
                'public_id' => $profile->cv_public_id,
            ]);
            Storage::disk('cloudinary')->delete($profile->cv_public_id);
        }

        // Upload to Cloudinary
        Log::info('Uploading CV to Cloudinary (upload-and-analyze)', [
            'user_id' => $user->_id,
            'file_name' => $request->file('cv')->getClientOriginalName(),
            'file_size' => $request->file('cv')->getSize(),
            'file_type' => $request->file('cv')->getMimeType(),
            'cloudinary_folder' => 'job-seeker-cvs',
        ]);
        
        $publicId = $request->file('cv')->store('job-seeker-cvs', 'cloudinary');
        $cvUrl    = Storage::disk('cloudinary')->url($publicId);
        
        Log::info('CV uploaded to Cloudinary successfully', [
            'user_id' => $user->_id,
            'public_id' => $publicId,
            'cv_url' => $cvUrl,
        ]);

        try {
            // Pass the public Cloudinary URL to the AI service
            Log::info('Sending CV to AI analysis service (upload-and-analyze)', [
                'user_id' => $user->_id,
                'cv_url' => $cvUrl,
                'resume_id' => (string) $user->_id,
            ]);
            
            $analysis = $cvAnalysisService->analyze($cvUrl, (string) $user->_id);
            
            Log::info('AI analysis completed successfully (upload-and-analyze)', [
                'user_id' => $user->_id,
                'analysis_fields_found' => array_keys($analysis),
                'has_full_name' => isset($analysis['full_name']),
                'has_skills' => isset($analysis['skills']) && count($analysis['skills']) > 0,
                'ats_score' => $analysis['ats_score'] ?? 'not provided',
            ]);
        } catch (CvAnalysisException $e) {
            // Remove the just-uploaded file since analysis failed
            Log::error('AI analysis failed, removing uploaded file (upload-and-analyze)', [
                'user_id' => $user->_id,
                'error_message' => $e->getMessage(),
                'http_status' => $e->getHttpStatusCode(),
                'public_id' => $publicId,
            ]);
            
            Storage::disk('cloudinary')->delete($publicId);

            $statusCode = $e->getHttpStatusCode();

            if ($statusCode === 422) {
                return response()->json([
                    'message' => 'CV analysis failed',
                    'reason' => $e->getMessage(),
                ], 422);
            }

            return response()->json([
                'message' => 'CV analysis service unavailable',
            ], 502);
        }

        // Map AI response to profile fields
        $updateData = [
            'resume' => $cvUrl,
            'resume_public_id' => $publicId,
            'cv_file_path' => $cvUrl,
            'cv_public_id' => $publicId,
            'ai_full_name' => $analysis['full_name'] ?? null,
            'ai_email' => $analysis['email'] ?? null,
            'ai_phone' => $analysis['phone'] ?? null,
            'ai_location' => $analysis['location'] ?? null,
            'ai_summary' => $analysis['summary'] ?? null,
            'ai_skills' => $analysis['skills'] ?? [],
            'ai_work_history' => $analysis['work_history'] ?? [],
            'ai_education_history' => $analysis['education_history'] ?? [],
            'ai_projects' => $analysis['projects'] ?? [],
            'ai_languages' => $analysis['languages'] ?? [],
            'ai_overall_evaluation' => $analysis['ai_overall_evaluation'] ?? null,
            'ats_score' => $analysis['ats_score'] ?? null,
            'ai_analyzed_at' => now(),
        ];

        // Extract social links if provided by AI
        if (! empty($analysis['linkedin']) || ! empty($analysis['github'])) {
            $socialLinks = [];
            if (! empty($analysis['linkedin'])) {
                $socialLinks['linkedin'] = $analysis['linkedin'];
            }
            if (! empty($analysis['github'])) {
                $socialLinks['github'] = $analysis['github'];
            }
            $updateData['ai_social_links'] = $socialLinks;
        }

        $profile->update($updateData);

        return response()->json([
            'message' => 'CV uploaded and analyzed successfully',
            'resume_url' => $cvUrl,
            'profile' => $profile->fresh(),
        ], 200);
    }

    /**
     * Upload resume
     *
     * Uploads a resume file to the job seeker's profile and triggers AI analysis.
     * Populates all `ai_*` profile fields and `ats_score` with extracted data.
     *
     * @bodyParam resume file required PDF/DOC/DOCX file, max 10 MB.
     *
     * @response 200 {
     *   "message": "Resume uploaded and analyzed successfully",
     *   "resume_url": "https://res.cloudinary.com/.../resume.pdf",
     *   "profile": {
     *     "id": "664f1a2b3c4d5e6f7a8b9c0d",
     *     "ats_score": 85,
     *     "ai_skills": ["PHP", "Laravel", "MongoDB"],
     *     "ai_summary": "Experienced backend developer...",
     *     "ai_analyzed_at": "2024-01-15T00:00:00Z"
     *   }
     * }
     * @response 422 { "errors": { "resume": ["The resume field is required."] } }
     * @response 422 { "message": "Resume analysis failed", "reason": "Resume content could not be parsed." }
     * @response 502 { "message": "Resume analysis service unavailable" }
     */
    // Upload resume separately
    public function uploadResume(Request $request, CvAnalysisService $cvAnalysisService)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'resume' => 'required|file|mimes:pdf,doc,docx|max:10240', // 10 MB max
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        $profile = $user->jobSeekerProfile()->firstOrCreate(['user_id' => $user->_id]);

        // Log start of resume upload process
        Log::info('Starting resume upload process', [
            'user_id' => $user->_id,
            'profile_exists' => $profile->exists,
            'existing_resume' => $profile->resume_public_id ? 'yes' : 'no',
            'existing_cv' => $profile->cv_public_id ? 'yes' : 'no',
        ]);

        // Delete previous resume/CV from Cloudinary if it exists
        if ($profile->resume_public_id) {
            Log::info('Deleting previous resume from Cloudinary', [
                'user_id' => $user->_id,
                'public_id' => $profile->resume_public_id,
            ]);
            Storage::disk('cloudinary')->delete($profile->resume_public_id);
        }
        // Also delete previous CV if it exists in separate field
        if ($profile->cv_public_id && $profile->cv_public_id !== $profile->resume_public_id) {
            Log::info('Deleting previous CV from Cloudinary', [
                'user_id' => $user->_id,
                'public_id' => $profile->cv_public_id,
            ]);
            Storage::disk('cloudinary')->delete($profile->cv_public_id);
        }

        // Upload to Cloudinary
        Log::info('Uploading resume to Cloudinary', [
            'user_id' => $user->_id,
            'file_name' => $request->file('resume')->getClientOriginalName(),
            'file_size' => $request->file('resume')->getSize(),
            'file_type' => $request->file('resume')->getMimeType(),
        ]);
        
        $publicId = $request->file('resume')->store('job-seeker-resumes', 'cloudinary');
        $resumeUrl = Storage::disk('cloudinary')->url($publicId);
        
        Log::info('Resume uploaded to Cloudinary successfully', [
            'user_id' => $user->_id,
            'public_id' => $publicId,
            'resume_url' => $resumeUrl,
            'cloudinary_folder' => 'job-seeker-resumes',
        ]);

        try {
            // Pass the public Cloudinary URL to the AI service
            Log::info('Sending resume to AI analysis service', [
                'user_id' => $user->_id,
                'resume_url' => $resumeUrl,
                'resume_id' => (string) $user->_id,
            ]);
            
            $analysis = $cvAnalysisService->analyze($resumeUrl, (string) $user->_id);
            
            Log::info('AI analysis completed successfully', [
                'user_id' => $user->_id,
                'analysis_fields_found' => array_keys($analysis),
                'has_full_name' => isset($analysis['full_name']),
                'has_skills' => isset($analysis['skills']) && count($analysis['skills']) > 0,
                'ats_score' => $analysis['ats_score'] ?? 'not provided',
            ]);
        } catch (CvAnalysisException $e) {
            // Remove the just-uploaded file since analysis failed
            Log::error('AI analysis failed, removing uploaded file', [
                'user_id' => $user->_id,
                'error_message' => $e->getMessage(),
                'http_status' => $e->getHttpStatusCode(),
                'public_id' => $publicId,
            ]);
            
            Storage::disk('cloudinary')->delete($publicId);

            $statusCode = $e->getHttpStatusCode();

            if ($statusCode === 422) {
                return response()->json([
                    'message' => 'Resume analysis failed',
                    'reason' => $e->getMessage(),
                ], 422);
            }

            return response()->json([
                'message' => 'Resume analysis service unavailable',
            ], 502);
        }

        // Map AI response to profile fields
        $updateData = [
            'resume' => $resumeUrl,
            'resume_public_id' => $publicId,
            'cv_file_path' => $resumeUrl,
            'cv_public_id' => $publicId,
            'ai_full_name' => $analysis['full_name'] ?? null,
            'ai_email' => $analysis['email'] ?? null,
            'ai_phone' => $analysis['phone'] ?? null,
            'ai_location' => $analysis['location'] ?? null,
            'ai_summary' => $analysis['summary'] ?? null,
            'ai_skills' => $analysis['skills'] ?? [],
            'ai_work_history' => $analysis['work_history'] ?? [],
            'ai_education_history' => $analysis['education_history'] ?? [],
            'ai_projects' => $analysis['projects'] ?? [],
            'ai_languages' => $analysis['languages'] ?? [],
            'ai_overall_evaluation' => $analysis['ai_overall_evaluation'] ?? null,
            'ats_score' => $analysis['ats_score'] ?? null,
            'ai_analyzed_at' => now(),
        ];

        // Extract social links if provided by AI
        if (! empty($analysis['linkedin']) || ! empty($analysis['github'])) {
            $socialLinks = [];
            if (! empty($analysis['linkedin'])) {
                $socialLinks['linkedin'] = $analysis['linkedin'];
            }
            if (! empty($analysis['github'])) {
                $socialLinks['github'] = $analysis['github'];
            }
            $updateData['ai_social_links'] = $socialLinks;
        }

        // Log profile update details
        Log::info('Updating profile with AI analysis results', [
            'user_id' => $user->_id,
            'fields_updated' => array_keys($updateData),
            'ai_fields_populated' => array_filter($updateData, function($value, $key) {
                return str_starts_with($key, 'ai_') && !empty($value);
            }, ARRAY_FILTER_USE_BOTH),
            'ats_score' => $analysis['ats_score'] ?? null,
            'skills_count' => count($analysis['skills'] ?? []),
            'work_history_count' => count($analysis['work_history'] ?? []),
            'education_history_count' => count($analysis['education_history'] ?? []),
        ]);

        $profile->update($updateData);

        Log::info('Resume upload and analysis completed successfully', [
            'user_id' => $user->_id,
            'profile_updated' => true,
            'resume_url' => $resumeUrl,
            'ai_analyzed_at' => now()->toISOString(),
        ]);

        return response()->json([
            'message' => 'Resume uploaded and analyzed successfully',
            'resume_url' => $resumeUrl,
            'profile' => $profile->fresh(),
        ], 200);
    }

    /**
     * Delete saved resume
     *
     * Removes the stored resume file from the job seeker's profile.
     *
     * @response 200 { "message": "Resume deleted successfully" }
     * @response 404 { "message": "No resume found on your profile" }
     */
    public function deleteResume(Request $request)
    {
        $user = $request->user();
        $profile = $user->jobSeekerProfile;

        if (! $profile || (! $profile->resume && ! $profile->cv_file_path)) {
            Log::info('No resume found to delete', [
                'user_id' => $user->_id,
                'has_resume' => $profile && $profile->resume ? 'yes' : 'no',
                'has_cv_file_path' => $profile && $profile->cv_file_path ? 'yes' : 'no',
            ]);
            return response()->json(['message' => 'No resume found on your profile'], 404);
        }

        Log::info('Starting resume deletion process', [
            'user_id' => $user->_id,
            'resume_url' => $profile->resume,
            'cv_file_path' => $profile->cv_file_path,
            'resume_public_id' => $profile->resume_public_id,
            'cv_public_id' => $profile->cv_public_id,
            'ai_data_present' => $profile->ai_full_name || $profile->ats_score ? 'yes' : 'no',
        ]);

        // Delete from Cloudinary if public IDs exist
        if ($profile->resume_public_id) {
            Log::info('Deleting resume from Cloudinary', [
                'user_id' => $user->_id,
                'public_id' => $profile->resume_public_id,
            ]);
            Storage::disk('cloudinary')->delete($profile->resume_public_id);
        }
        if ($profile->cv_public_id && $profile->cv_public_id !== $profile->resume_public_id) {
            Log::info('Deleting CV from Cloudinary', [
                'user_id' => $user->_id,
                'public_id' => $profile->cv_public_id,
                'different_from_resume' => $profile->cv_public_id !== $profile->resume_public_id ? 'yes' : 'no',
            ]);
            Storage::disk('cloudinary')->delete($profile->cv_public_id);
        }

        // Clear all resume/CV related fields
        $profile->update([
            'resume' => null,
            'resume_public_id' => null,
            'cv_file_path' => null,
            'cv_public_id' => null,
        ]);

        Log::info('Resume deletion completed successfully', [
            'user_id' => $user->_id,
            'files_deleted' => 'yes',
            'fields_cleared' => 'yes',
            'ai_data_retained' => 'yes', // AI data remains even after file deletion
        ]);

        return response()->json(['message' => 'Resume deleted successfully']);
    }

    /**
     * Save default cover letter
     *
     * Saves a default cover letter on the profile. It will be used automatically when applying
     * to jobs if no per-application cover letter is provided.
     *
     * @bodyParam cover_letter string required The default cover letter text. Max 2000 chars.
     *
     * @response 200 { "message": "Default cover letter saved", "default_cover_letter": "..." }
     */
    public function saveDefaultCoverLetter(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'cover_letter' => 'required|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $profile = $user->jobSeekerProfile()->firstOrCreate(['user_id' => $user->_id]);
        $profile->update(['default_cover_letter' => $request->cover_letter]);

        return response()->json([
            'message'               => 'Default cover letter saved',
            'default_cover_letter'  => $profile->default_cover_letter,
        ]);
    }

    /**
     * Delete default cover letter
     *
     * Removes the saved default cover letter from the job seeker's profile.
     *
     * @response 200 { "message": "Default cover letter deleted" }
     * @response 404 { "message": "No default cover letter found on your profile" }
     */
    public function deleteDefaultCoverLetter(Request $request)
    {
        $user = $request->user();
        $profile = $user->jobSeekerProfile;

        if (! $profile || ! $profile->default_cover_letter) {
            return response()->json(['message' => 'No default cover letter found on your profile'], 404);
        }

        $profile->update(['default_cover_letter' => null]);

        return response()->json(['message' => 'Default cover letter deleted']);
    }

    /**
     * Search jobs
     *
     * Paginated search of active job posts for the authenticated job seeker.
     *
     * @queryParam keyword string Search in title, description, company name. Max 100 chars. Example: React
     * @queryParam location string Partial location match. Max 100 chars. Example: Beirut
     * @queryParam job_type string One of: full_time, part_time, contract, freelance. Example: full_time
     * @queryParam category string Example: Engineering
     * @queryParam min_salary number Minimum salary. Example: 2000
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
            'keyword' => 'nullable|string|max:100',
            'location' => 'nullable|string|max:100',
            'job_type' => 'nullable|string|in:full_time,part_time,contract,freelance',
            'category' => 'nullable|string|max:100',
            'min_salary' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = JobPost::where('is_active', true);

        if ($keyword = $request->query('keyword')) {
            $regex = new \MongoDB\BSON\Regex($keyword, 'i');
            $query->where(function ($q) use ($regex) {
                $q->where('title', $regex)
                    ->orWhere('description', $regex)
                    ->orWhere('company_name', $regex);
            });
        }

        if ($location = $request->query('location')) {
            $query->where('city', new \MongoDB\BSON\Regex($location, 'i'));
        }

        if ($jobType = $request->query('job_type')) {
            $query->where('job_type', $jobType);
        }

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        if (($minSalary = $request->query('min_salary')) !== null) {
            $query->where('salary_from', '>=', (int) $minSalary);
        }

        $jobs = $query->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json([
            'jobs' => $jobs,
        ]);
    }
}
