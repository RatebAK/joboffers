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
            $profile = $user->jobSeekerProfile()->create([
                'analysis_status' => null
            ]);
        }

        return response()->json([
            'profile'   => $profile,
            'documents' => [
                'cv_url'               => $profile->cv_file_path,
                'cv_analyzed_at'       => $profile->ai_analyzed_at,
                'resume_url'           => $profile->resume,
                'default_cover_letter' => $profile->default_cover_letter,
                'analysis_status'      => $profile->analysis_status,
                'analysis_error'       => $profile->analysis_error,
                'analysis_started_at'  => $profile->analysis_started_at,
                'analysis_completed_at' => $profile->analysis_completed_at,
            ],
        ]);
    }

    /**
     * Update personal information
     *
     * Updates the authenticated job seeker's personal information fields.
     * Send `image` as a file upload (JPEG/PNG/WEBP, max 2MB) to upload a profile photo to Cloudinary.
     * All other fields are sent as regular form fields.
     *
     * @bodyParam first_name string Example: Jane
     * @bodyParam last_name string Example: Smith
     * @bodyParam full_name string Example: Jane Smith
     * @bodyParam image file JPEG/PNG/WEBP profile photo, max 2MB.
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
     *   "profile": { "id": "664f1a2b3c4d5e6f7a8b9c0d", "full_name": "Jane Smith", "image": "https://res.cloudinary.com/.../photo.jpg" }
     * }
     */
    public function updatePersonalInfo(Request $request)
    {
        $user = $request->user();
        $validator = Validator::make($request->all(), [
            'first_name'     => 'nullable|string|max:50',
            'last_name'      => 'nullable|string|max:50',
            'full_name'      => 'nullable|string|max:100',
            'image'          => 'nullable|image|mimes:jpeg,png,webp|max:2048',
            'gender'         => 'nullable|string|in:male,female,other,prefer_not_to_say',
            'nationality'    => 'nullable|string|max:100',
            'city'           => 'nullable|string|max:100',
            'location'       => 'nullable|string|max:255',
            'address'        => 'nullable|string|max:255',
            'phone'          => 'nullable|string|max:20',
            'date_of_birth'  => 'nullable|string|max:20',
            'marital_status' => 'nullable|string|in:single,married,divorced,widowed,prefer_not_to_say',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Handle profile image upload
        if ($request->hasFile('image')) {
            $profile = $user->jobSeekerProfile;
            // Delete old image from Cloudinary if exists
            if ($profile && $profile->image_public_id) {
                Storage::disk('cloudinary')->delete($profile->image_public_id);
            }
            $path = $request->file('image')->store('job-seeker-photos', 'cloudinary');
            $data['image'] = Storage::disk('cloudinary')->url($path);
            $data['image_public_id'] = $path;
        }

        $profile = $user->jobSeekerProfile()->updateOrCreate(
            ['user_id' => $user->_id],
            $data
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
     * ## How Uploaded CVs Benefit Job Applications:
     *
     * Uploading a CV enables automatic job applications and provides these advantages:
     *
     * 1. **Automatic Application Attachments**: Your CV is automatically attached when you apply to jobs
     * 2. **ATS Score Generation**: Get an ATS score that employers use to filter candidates (65+ is recommended)
     * 3. **AI-Powered Profile**: Skills, work history, education are automatically extracted
     * 4. **Better Visibility**: Employers see your complete AI-analyzed profile with extracted data
     * 5. **Job Matching**: Enables AI-powered job matching services
     * 6. **Resume Coach**: Get AI-powered resume improvement suggestions
     *
     * ## Workflow for Job Applications:
     * 1. Upload CV → Get AI analysis → ATS score generated
     * 2. Apply to jobs → Your CV automatically attached
     * 3. Employers see → Your ATS score + AI-extracted skills
     *
     * @bodyParam cv file required PDF/DOC/DOCX file, max 10 MB.
     *
     * @response 200 {
     *   "message": "CV uploaded successfully. Analysis is being processed.",
     *   "resume_url": "https://res.cloudinary.com/.../cv.pdf",
     *   "analysis_status": "pending",
     *   "profile": {
     *     "id": "664f1a2b3c4d5e6f7a8b9c0d",
     *     "ats_score": null,
     *     "ai_skills": [],
     *     "ai_work_history": [],
     *     "ai_analyzed_at": null,
     *     "analysis_status": "pending"
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
        
        $file = $request->file('cv');
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $extension    = $file->getClientOriginalExtension();
        $mimeType     = $file->getMimeType();

        // Upload directly via Cloudinary SDK so we can preserve filename + extension
        $cloudinary = app(\Cloudinary\Cloudinary::class);
        $result = $cloudinary->uploadApi()->upload($file->getRealPath(), [
            'folder'          => 'job-seeker-cvs',
            'public_id'       => $originalName . '_' . uniqid(),
            'resource_type'   => 'raw',
            'use_filename'    => true,
            'unique_filename' => true,
            'format'          => $extension,
        ]);

        $publicId = $result['public_id'];
        $cvUrl    = $result['secure_url'];
        
        Log::info('CV uploaded to Cloudinary successfully', [
            'user_id' => $user->_id,
            'public_id' => $publicId,
            'cv_url' => $cvUrl,
        ]);

        // Store CV immediately and set analysis status to pending
        $profile->update([
            'resume' => $cvUrl,
            'resume_public_id' => $publicId,
            'cv_file_path' => $cvUrl,
            'cv_public_id' => $publicId,
            'resume_file_type' => $mimeType,
            // Reset AI fields since we're starting fresh
            'ai_full_name' => null,
            'ai_email' => null,
            'ai_phone' => null,
            'ai_location' => null,
            'ai_summary' => null,
            'ai_skills' => [],
            'ai_work_history' => [],
            'ai_education_history' => [],
            'ai_projects' => [],
            'ai_languages' => [],
            'ai_social_links' => [],
            'ai_overall_evaluation' => null,
            'ats_score' => null,
            'ai_analyzed_at' => null,
            // Set analysis status
            'analysis_status' => 'pending',
            'analysis_error' => null,
            'analysis_started_at' => now(),
            'analysis_completed_at' => null,
        ]);

        // Start analysis in background (non-blocking)
        try {
            // Pass the public Cloudinary URL to the AI service
            Log::info('Sending CV to AI analysis service (upload-and-analyze)', [
                'user_id' => $user->_id,
                'cv_url' => $cvUrl,
                'resume_id' => (string) $user->_id,
            ]);
            
            // Update status to processing
            $profile->update(['analysis_status' => 'processing']);
            
            $analysis = $cvAnalysisService->analyze($cvUrl, (string) $user->_id, $mimeType);
            
            Log::info('AI analysis completed successfully (upload-and-analyze)', [
                'user_id' => $user->_id,
                'analysis_fields_found' => array_keys($analysis),
                'has_full_name' => isset($analysis['full_name']),
                'has_skills' => isset($analysis['skills']) && count($analysis['skills']) > 0,
                'ats_score' => $analysis['ats_score'] ?? 'not provided',
            ]);

            // Map AI response to profile fields
            $updateData = [
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
                // Update analysis status
                'analysis_status' => 'completed',
                'analysis_error' => null,
                'analysis_completed_at' => now(),
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

        } catch (CvAnalysisException $e) {
            Log::error('AI analysis failed (upload-and-analyze)', [
                'user_id' => $user->_id,
                'error_message' => $e->getMessage(),
                'http_status' => $e->getHttpStatusCode(),
            ]);

            $profile->update([
                'analysis_status' => 'error',
                'analysis_error' => $e->getMessage(),
                'analysis_completed_at' => now(),
            ]);

            $httpStatus = $e->getHttpStatusCode();

            if ($httpStatus === 422) {
                return response()->json(['message' => 'CV analysis failed', 'error' => $e->getMessage()], 422);
            }

            return response()->json(['message' => 'CV analysis service unavailable', 'error' => $e->getMessage()], 502);
        }

        return response()->json([
            'message' => 'CV uploaded and analyzed successfully.',
            'resume_url' => $cvUrl,
            'analysis_status' => 'completed',
            'profile' => $profile->fresh(),
        ], 200);
    }

    /**
     * Upload resume
     *
     * Uploads a resume file to the job seeker's profile and triggers AI analysis.
     * Populates all `ai_*` profile fields and `ats_score` with extracted data.
     *
     * ## How Uploaded Resumes Benefit Job Applications:
     *
     * Uploading a resume enables automatic job applications and provides these advantages:
     *
     * 1. **Automatic Application Attachments**: Your resume is automatically attached when you apply to jobs
     * 2. **ATS Score Generation**: Get an ATS score that employers use to filter candidates
     * 3. **AI-Powered Profile**: Skills, work history, education are automatically extracted
     * 4. **Better Visibility**: Employers see your complete AI-analyzed profile
     * 5. **Job Matching**: Enables AI-powered job matching services
     *
     * ## Analysis Status Tracking:
     * - `pending`: Uploaded, analysis not started
     * - `processing`: Analysis in progress
     * - `completed`: Analysis successful, profile updated
     * - `error`: Analysis failed, resume still saved
     *
     * Check status: `GET /job-seeker/resume/analysis-status`
     * Retry failed: `POST /job-seeker/resume/retry-analysis`
     *
     * @bodyParam resume file required PDF/DOC/DOCX file, max 10 MB.
     *
     * @response 200 {
     *   "message": "Resume uploaded successfully. Analysis is being processed.",
     *   "resume_url": "https://res.cloudinary.com/.../resume.pdf",
     *   "analysis_status": "pending",
     *   "profile": {
     *     "id": "664f1a2b3c4d5e6f7a8b9c0d",
     *     "ats_score": null,
     *     "ai_skills": [],
     *     "ai_analyzed_at": null,
     *     "analysis_status": "pending"
     *   }
     * }
     * @response 422 { "errors": { "resume": ["The resume field is required."] } }
     * @response 422 { "message": "Resume analysis failed", "reason": "Resume content could not be parsed." }
     * @response 502 { "message": "Resume analysis service unavailable" }
     */
    // Upload resume separately - NON-BLOCKING VERSION
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
        Log::info('Starting resume upload process (non-blocking)', [
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
        
        $file = $request->file('resume');
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $extension    = $file->getClientOriginalExtension();
        $mimeType     = $file->getMimeType();

        // Upload directly via Cloudinary SDK so we can preserve filename + extension
        $cloudinary = app(\Cloudinary\Cloudinary::class);
        $result = $cloudinary->uploadApi()->upload($file->getRealPath(), [
            'folder'          => 'job-seeker-resumes',
            'public_id'       => $originalName . '_' . uniqid(),
            'resource_type'   => 'raw',
            'use_filename'    => true,
            'unique_filename' => true,
            'format'          => $extension, // tells Cloudinary to append the extension to the URL
        ]);

        $publicId  = $result['public_id'];
        $resumeUrl = $result['secure_url'];
        
        Log::info('Resume uploaded to Cloudinary successfully', [
            'user_id' => $user->_id,
            'public_id' => $publicId,
            'resume_url' => $resumeUrl,
            'cloudinary_folder' => 'job-seeker-resumes',
        ]);

        // Store resume immediately and set analysis status to pending
        $profile->update([
            'resume' => $resumeUrl,
            'resume_public_id' => $publicId,
            'cv_file_path' => $resumeUrl,
            'cv_public_id' => $publicId,
            'resume_file_type' => $mimeType,
            // Reset AI fields since we're starting fresh
            'ai_full_name' => null,
            'ai_email' => null,
            'ai_phone' => null,
            'ai_location' => null,
            'ai_summary' => null,
            'ai_skills' => [],
            'ai_work_history' => [],
            'ai_education_history' => [],
            'ai_projects' => [],
            'ai_languages' => [],
            'ai_social_links' => [],
            'ai_overall_evaluation' => null,
            'ats_score' => null,
            'ai_analyzed_at' => null,
            // Set analysis status
            'analysis_status' => 'pending',
            'analysis_error' => null,
            'analysis_started_at' => now(),
            'analysis_completed_at' => null,
        ]);

        // Start analysis in background (non-blocking)
        try {
            // Pass the public Cloudinary URL to the AI service
            Log::info('Sending resume to AI analysis service (non-blocking)', [
                'user_id' => $user->_id,
                'resume_url' => $resumeUrl,
                'resume_id' => (string) $user->_id,
            ]);
            
            // Update status to processing
            $profile->update(['analysis_status' => 'processing']);
            
            // Call AI service - this will still be synchronous but the user gets response immediately
            $analysis = $cvAnalysisService->analyze($resumeUrl, (string) $user->_id, $mimeType);
            
            Log::info('AI analysis completed successfully', [
                'user_id' => $user->_id,
                'analysis_fields_found' => array_keys($analysis),
                'has_full_name' => isset($analysis['full_name']),
                'has_skills' => isset($analysis['skills']) && count($analysis['skills']) > 0,
                'ats_score' => $analysis['ats_score'] ?? 'not provided',
            ]);

            // Map AI response to profile fields
            $updateData = [
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
                // Update analysis status
                'analysis_status' => 'completed',
                'analysis_error' => null,
                'analysis_completed_at' => now(),
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

            Log::info('Resume analysis completed successfully', [
                'user_id' => $user->_id,
                'profile_updated' => true,
                'resume_url' => $resumeUrl,
                'ai_analyzed_at' => now()->toISOString(),
            ]);

        } catch (CvAnalysisException $e) {
            // Analysis failed, but we KEEP the file (unlike before)
            Log::error('AI analysis failed, but keeping uploaded file', [
                'user_id' => $user->_id,
                'error_message' => $e->getMessage(),
                'http_status' => $e->getHttpStatusCode(),
                'public_id' => $publicId,
                'resume_url' => $resumeUrl,
            ]);
            
            // Update analysis status to error
            $profile->update([
                'analysis_status' => 'error',
                'analysis_error' => $e->getMessage(),
                'analysis_completed_at' => now(),
            ]);

            // Don't return error response here - the file is saved, analysis failed
            // The user can check status via the profile endpoint
        }

        Log::info('Resume upload process completed (non-blocking)', [
            'user_id' => $user->_id,
            'profile_updated' => true,
            'resume_url' => $resumeUrl,
            'analysis_status' => $profile->fresh()->analysis_status,
        ]);

        return response()->json([
            'message' => 'Resume uploaded successfully. Analysis is being processed.',
            'resume_url' => $resumeUrl,
            'analysis_status' => $profile->fresh()->analysis_status,
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

    /**
     * Get resume and AI analysis
     *
     * Returns the authenticated job seeker's stored resume URL and full AI analysis results.
     *
     * @response 200 {
     *   "resume_url": "https://res.cloudinary.com/.../resume.pdf",
     *   "analysis_status": "completed",
     *   "analysis_error": null,
     *   "analysis_started_at": "2026-07-18T09:31:00Z",
     *   "analysis_completed_at": "2026-07-18T09:31:18Z",
     *   "ats_score": 65,
     *   "ai_full_name": "John Smith",
     *   "ai_email": "john@example.com",
     *   "ai_phone": "+1 555 0100",
     *   "ai_location": "New York, NY",
     *   "ai_summary": "Experienced developer...",
     *   "ai_skills": ["PHP", "Laravel", "MongoDB"],
     *   "ai_work_history": [{ "company": "Acme", "role": "Developer", "duration": "2020-2023", "description": "..." }],
     *   "ai_education_history": [{ "institution": "MIT", "degree": "BSc Computer Science", "year": "2020" }],
     *   "ai_projects": [],
     *   "ai_languages": ["English", "Arabic"],
     *   "ai_overall_evaluation": "Strong profile with relevant experience...",
     *   "ai_analyzed_at": "2026-07-18T09:31:18Z"
     * }
     * @response 404 { "message": "No resume found. Please upload a resume first." }
     */
    public function getResume(Request $request)
    {
        $user = $request->user();
        $profile = $user->jobSeekerProfile;

        if (! $profile || ! $profile->resume) {
            return response()->json(['message' => 'No resume found. Please upload a resume first.'], 404);
        }

        return response()->json([
            'resume_url'             => $profile->resume,
            'resume_file_type'       => $profile->resume_file_type,
            'analysis_status'        => $profile->analysis_status,
            'analysis_error'         => $profile->analysis_error,
            'analysis_started_at'    => $profile->analysis_started_at,
            'analysis_completed_at'  => $profile->analysis_completed_at,
            'ats_score'              => $profile->ats_score,
            'ai_full_name'           => $profile->ai_full_name,
            'ai_email'               => $profile->ai_email,
            'ai_phone'               => $profile->ai_phone,
            'ai_location'            => $profile->ai_location,
            'ai_summary'             => $profile->ai_summary,
            'ai_skills'              => $profile->ai_skills ?? [],
            'ai_work_history'        => $profile->ai_work_history ?? [],
            'ai_education_history'   => $profile->ai_education_history ?? [],
            'ai_projects'            => $profile->ai_projects ?? [],
            'ai_languages'           => $profile->ai_languages ?? [],
            'ai_social_links'        => $profile->ai_social_links ?? [],
            'ai_overall_evaluation'  => $profile->ai_overall_evaluation,
            'ai_analyzed_at'         => $profile->ai_analyzed_at,
        ]);
    }

    /**
     * Check resume analysis status
     *
     * Returns the current analysis status of the uploaded resume/CV.
     *
     * @response 200 {
     *   "analysis_status": "completed",
     *   "analysis_error": null,
     *   "analysis_started_at": "2024-01-15T10:30:00Z",
     *   "analysis_completed_at": "2024-01-15T10:31:00Z",
     *   "resume_url": "https://res.cloudinary.com/.../resume.pdf",
     *   "has_ai_data": true,
     *   "profile": { "id": "664f1a2b3c4d5e6f7a8b9c0d", "ats_score": 85 }
     * }
     * @response 200 {
     *   "analysis_status": "error",
     *   "analysis_error": "Could not extract any valid text from the provided file URL.",
     *   "analysis_started_at": "2024-01-15T10:30:00Z",
     *   "analysis_completed_at": "2024-01-15T10:30:05Z",
     *   "resume_url": "https://res.cloudinary.com/.../resume.docx",
     *   "has_ai_data": false,
     *   "profile": { "id": "664f1a2b3c4d5e6f7a8b9c0d", "ats_score": null }
     * }
     */
    public function checkAnalysisStatus(Request $request)
    {
        $user = $request->user();
        $profile = $user->jobSeekerProfile;

        if (! $profile) {
            return response()->json([
                'message' => 'No profile found. Please upload a resume first.',
            ], 404);
        }

        return response()->json([
            'analysis_status' => $profile->analysis_status,
            'analysis_error' => $profile->analysis_error,
            'analysis_started_at' => $profile->analysis_started_at,
            'analysis_completed_at' => $profile->analysis_completed_at,
            'resume_url' => $profile->resume,
            'has_ai_data' => !empty($profile->ai_skills) || !empty($profile->ats_score),
            'profile' => $profile,
        ]);
    }

    /**
     * Retry failed resume analysis
     *
     * Retries the AI analysis for a previously uploaded resume that failed analysis.
     * Requires that a resume is already uploaded and analysis status is 'error'.
     *
     * @response 200 {
     *   "message": "Analysis retry started successfully",
     *   "analysis_status": "processing",
     *   "resume_url": "https://res.cloudinary.com/.../resume.pdf"
     * }
     * @response 400 {
     *   "message": "No resume found or analysis is not in error state"
     * }
     * @response 404 {
     *   "message": "No profile or resume found"
     * }
     */
    public function retryAnalysis(Request $request, CvAnalysisService $cvAnalysisService)
    {
        $user = $request->user();
        $profile = $user->jobSeekerProfile;

        if (! $profile || ! $profile->resume) {
            return response()->json([
                'message' => 'No profile or resume found. Please upload a resume first.',
            ], 404);
        }

        if ($profile->analysis_status !== 'error') {
            return response()->json([
                'message' => 'Analysis is not in error state. Current status: ' . $profile->analysis_status,
            ], 400);
        }

        Log::info('Retrying resume analysis', [
            'user_id' => $user->_id,
            'resume_url' => $profile->resume,
            'previous_error' => $profile->analysis_error,
        ]);

        // Reset analysis status
        $profile->update([
            'analysis_status' => 'processing',
            'analysis_error' => null,
            'analysis_started_at' => now(),
            'analysis_completed_at' => null,
        ]);

        // Retry analysis in background
        try {
            $analysis = $cvAnalysisService->analyze($profile->resume, (string) $user->_id, $profile->resume_file_type);
            
            Log::info('Retry analysis completed successfully', [
                'user_id' => $user->_id,
                'has_full_name' => isset($analysis['full_name']),
                'has_skills' => isset($analysis['skills']) && count($analysis['skills']) > 0,
                'ats_score' => $analysis['ats_score'] ?? 'not provided',
            ]);

            // Map AI response to profile fields
            $updateData = [
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
                // Update analysis status
                'analysis_status' => 'completed',
                'analysis_error' => null,
                'analysis_completed_at' => now(),
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

        } catch (CvAnalysisException $e) {
            Log::error('Retry analysis failed', [
                'user_id' => $user->_id,
                'error_message' => $e->getMessage(),
                'http_status' => $e->getHttpStatusCode(),
            ]);
            
            // Update analysis status to error
            $profile->update([
                'analysis_status' => 'error',
                'analysis_error' => $e->getMessage(),
                'analysis_completed_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Analysis retry started successfully',
            'analysis_status' => $profile->fresh()->analysis_status,
            'resume_url' => $profile->resume,
        ], 200);
    }
}