<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\JobPost;
use App\Models\JobSeekerProfile;
use Illuminate\Http\Request;
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
     * ## How Uploaded CVs Benefit Your Application
     *
     * When you have an uploaded and AI-analyzed CV in your profile, your application gains several advantages:
     *
     * 1. **Automatic Resume Attachment**: Your stored CV is automatically attached to applications if you don't provide a separate resume file
     * 2. **ATS Score Visibility**: Employers see your ATS (Applicant Tracking System) score when reviewing applications
     * 3. **AI-Extracted Profile Data**: Employers can see your AI-extracted skills, work history, and education
     * 4. **Better Job Matching**: AI-analyzed profiles get better matches through the matching services
     * 5. **Default Cover Letter**: Set a default cover letter that's automatically used if you don't provide one
     *
     * ## Steps to Apply with Uploaded CV:
     * 1. Upload CV: `POST /job-seeker/resume/upload-and-analyze` or `POST /job-seeker/resume/upload`
     * 2. Apply to job: `POST /job-seeker/apply` with `job_post_id`
     * 3. Your stored CV will be automatically attached
     *
     * @bodyParam job_post_id string required The ID of the job post to apply to. Example: 664f1a2b3c4d5e6f7a8b9c0d
     * @bodyParam cover_letter string Optional cover letter. Max 1000 chars. Example: I am very interested in this position.
     * @bodyParam resume file Optional PDF/DOC resume file (max 5 MB). Overrides profile CV.
     * @bodyParam education string Optional education summary. Max 255 chars.
     * @bodyParam last_work string Optional last work experience. Max 255 chars.
     * @bodyParam years_of_experience integer Optional years of experience. Min 0, max 60.
     * @bodyParam why_join string Optional motivation for joining. Max 2000 chars.
     * @bodyParam what_to_add string Optional skills/contributions you can add. Max 2000 chars.
     * @bodyParam positions_suited_for array Optional array of suitable positions.
     * @bodyParam notice_period string Optional notice period. Max 100 chars.
     * @bodyParam expected_salary string Optional expected salary. Max 100 chars.
     * @bodyParam answers object[] Answers to the job post's screening questions. Required questions must be answered.
     * @bodyParam answers[].question string required The exact question text from the job post. Example: Describe your last project.
     * @bodyParam answers[].answer string required The applicant's answer. Example: I led a team of 4 building a payments API.
     *
     * @response 201 {
     *   "message": "Application submitted successfully",
     *   "application": {
     *     "id": "664f1a2b3c4d5e6f7a8b9c0f",
     *     "job_post_id": "664f1a2b3c4d5e6f7a8b9c0d",
     *     "status": "pending",
     *     "applied_at": "2024-01-15T00:00:00Z",
     *     "resume": "https://res.cloudinary.com/.../cv.pdf"
     *   }
     * }
     * @response 404 { "message": "Job post not found or is not active" }
     * @response 409 { "message": "You have already applied to this job" }
     */
    public function store(Request $request)
    {
        set_time_limit(300);

        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'job_post_id'           => 'required|string',
            'cover_letter'          => 'nullable|string|max:1000',
            'resume'                => 'nullable|file|mimes:pdf,doc,docx|max:5120',
            // Eager applicant profile fields
            'education'             => 'nullable|string|max:255',
            'last_work'             => 'nullable|string|max:255',
            'years_of_experience'   => 'nullable|integer|min:0|max:60',
            'why_join'              => 'nullable|string|max:2000',
            'what_to_add'           => 'nullable|string|max:2000',
            'positions_suited_for'  => 'nullable|array',
            'positions_suited_for.*'=> 'string|max:100',
            'notice_period'         => 'nullable|string|max:100',
            'expected_salary'       => 'nullable|string|max:100',
            'answers'               => 'nullable|array',
            'answers.*.question'    => 'required_with:answers|string|max:500',
            'answers.*.answer'      => 'required_with:answers|string|max:2000',
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

        // Match submitted answers to the job post's questions and enforce required ones
        $resolvedAnswers = $this->resolveAnswers($jobPost->questions ?? [], $data['answers'] ?? []);

        if ($resolvedAnswers instanceof \Illuminate\Http\JsonResponse) {
            return $resolvedAnswers;
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
            $file      = $request->file('resume');
            $extension = $file->getClientOriginalExtension();
            $baseName  = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

            $cloudinary = app(\Cloudinary\Cloudinary::class);
            $result = $cloudinary->uploadApi()->upload($file->getRealPath(), [
                'folder'          => 'application-resumes',
                'public_id'       => $baseName . '_' . uniqid(),
                'resource_type'   => 'raw',
                'use_filename'    => true,
                'unique_filename' => true,
                'format'          => $extension,
            ]);

            $data['resume'] = $result['secure_url'];
        } else {
            $profile = $user->jobSeekerProfile;
            $data['resume'] = $profile->cv_file_path ?? null;
        }

        // Resolve cover letter: request value > profile default
        if (empty($data['cover_letter'])) {
            $profile = $profile ?? $user->jobSeekerProfile;
            $data['cover_letter'] = $profile->default_cover_letter ?? null;
        }

        $application = Application::create([
            'user_id'               => $user->_id,
            'job_post_id'           => $data['job_post_id'],
            'cover_letter'          => $data['cover_letter'] ?? null,
            'resume'                => $data['resume'],
            'status'                => 'pending',
            'applied_at'            => now(),
            // Eager applicant profile fields
            'education'             => $data['education'] ?? null,
            'last_work'             => $data['last_work'] ?? null,
            'years_of_experience'   => $data['years_of_experience'] ?? null,
            'why_join'              => $data['why_join'] ?? null,
            'what_to_add'           => $data['what_to_add'] ?? null,
            'positions_suited_for'  => $data['positions_suited_for'] ?? null,
            'notice_period'         => $data['notice_period'] ?? null,
            'expected_salary'       => $data['expected_salary'] ?? null,
            'answers'               => $resolvedAnswers,
        ]);

        return response()->json([
            'message'     => 'Application submitted successfully',
            'application' => $application->load('jobPost'),
        ], 201);
    }

    /**
     * Pairs submitted answers with a job post's screening questions.
     *
     * Answers are keyed by their `question` text. Only questions defined on the
     * job post are kept; unknown questions are ignored. Every question marked
     * `required` on the job post must have a non-empty answer.
     *
     * @param  array  $questions        The job post's questions: [{ question, required }]
     * @param  array  $submittedAnswers The applicant's answers: [{ question, answer }]
     * @return array|\Illuminate\Http\JsonResponse  Resolved [{ question, answer }] or a 422 response
     */
    private function resolveAnswers(array $questions, array $submittedAnswers)
    {
        if (empty($questions)) {
            return [];
        }

        $answersByQuestion = collect($submittedAnswers)
            ->mapWithKeys(fn ($a) => [trim($a['question']) => trim($a['answer'])]);

        $resolved       = [];
        $missingRequired = [];

        foreach ($questions as $question) {
            $text   = trim($question['question'] ?? '');
            $answer = $answersByQuestion->get($text);

            if (($question['required'] ?? false) && ($answer === null || $answer === '')) {
                $missingRequired[] = $text;
                continue;
            }

            if ($answer !== null && $answer !== '') {
                $resolved[] = ['question' => $text, 'answer' => $answer];
            }
        }

        if (! empty($missingRequired)) {
            return response()->json([
                'message' => 'Please answer all required questions.',
                'errors'  => ['answers' => $missingRequired],
            ], 422);
        }

        return $resolved;
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
