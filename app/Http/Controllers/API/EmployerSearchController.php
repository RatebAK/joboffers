<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Illuminate\Http\Request;

class EmployerSearchController extends Controller
{
    /**
     * Search job seekers
     *
     * Paginated list of actively-seeking job seekers with optional filters. Only returns profiles where `is_actively_seeking = true`.
     *
     * @queryParam skills string Comma-separated list of required skills (all must match, case-insensitive). Example: React,TypeScript
     * @queryParam min_ats_score integer Minimum ATS score. Example: 70
     * @queryParam max_ats_score integer Maximum ATS score. Example: 95
     * @queryParam location string Partial match on AI-detected location. Example: Beirut
     * @queryParam keyword string Partial match on AI summary or current job title. Example: frontend
     * @queryParam page integer Page number. Example: 1
     *
     * @response 200 {
     *   "seekers": {
     *     "data": [
     *       {
     *         "id": "664f1a2b3c4d5e6f7a8b9c0d",
     *         "user_id": "664f1a2b3c4d5e6f7a8b9c0e",
     *         "current_job_title": "Frontend Developer",
     *         "ats_score": 82,
     *         "ai_skills": ["React", "TypeScript"],
     *         "ai_location": "Beirut, Lebanon",
     *         "is_actively_seeking": true
     *       }
     *     ],
     *     "current_page": 1, "per_page": 10, "total": 1, "total_pages": 1, "next_page": null, "prev_page": null
     *   }
     * }
     */
    public function index(Request $request)
    {
        $query = JobSeekerProfile::where('is_actively_seeking', true);

        // Skills filter: all specified skills must be present in ai_skills (case-insensitive)
        if ($request->filled('skills')) {
            $skills = array_filter(array_map('trim', explode(',', $request->skills)));

            foreach ($skills as $skill) {
                $query->where('ai_skills', 'elemMatch', [
                    '$regex'   => '^' . preg_quote($skill, '/') . '$',
                    '$options' => 'i',
                ]);
            }
        }

        // ATS score range filters
        if ($request->filled('min_ats_score')) {
            $query->where('ats_score', '>=', (int) $request->min_ats_score);
        }

        if ($request->filled('max_ats_score')) {
            $query->where('ats_score', '<=', (int) $request->max_ats_score);
        }

        // Location filter: case-insensitive partial match on ai_location
        if ($request->filled('location')) {
            $query->where('ai_location', 'regex', new \MongoDB\BSON\Regex(preg_quote($request->location, '/'), 'i'));
        }

        // Keyword filter: case-insensitive match on ai_summary OR current_job_title
        if ($request->filled('keyword')) {
            $pattern = new \MongoDB\BSON\Regex(preg_quote($request->keyword, '/'), 'i');
            $query->where(function ($q) use ($pattern) {
                $q->where('ai_summary', 'regex', $pattern)
                  ->orWhere('current_job_title', 'regex', $pattern);
            });
        }

        $profiles = $query->paginate(10);

        return response()->json(['seekers' => $profiles]);
    }

    /**
     * Get job seeker profile (employer view)
     *
     * Returns a specific job seeker's full profile by their user ID, with sensitive
     * personal fields excluded. Intended for employers reviewing candidates.
     *
     * @urlParam userId string required The job seeker's user ID. Example: 664f1a2b3c4d5e6f7a8b9c0e
     *
     * @response 200 {
     *   "user_id": "664f1a2b3c4d5e6f7a8b9c0e",
     *   "name": "Jane Smith",
     *   "image": "https://res.cloudinary.com/.../photo.jpg",
     *   "current_job_title": "Frontend Developer",
     *   "current_job_status": "employed",
     *   "job_level": "senior",
     *   "job_types": ["full_time", "remote"],
     *   "job_roles": ["frontend", "fullstack"],
     *   "years_of_experience": 5,
     *   "education_level": "bachelor",
     *   "expected_salary": 3000,
     *   "salary_range_from": 2500,
     *   "salary_range_to": 4000,
     *   "is_actively_seeking": true,
     *   "work_cities": ["Damascus", "Remote"],
     *   "city": "Damascus",
     *   "social_links": { "linkedin": "https://linkedin.com/in/jane", "github": null },
     *   "skills": [],
     *   "education_history": [],
     *   "work_experience": [],
     *   "ats_score": 82,
     *   "ai_skills": ["React", "TypeScript"],
     *   "ai_summary": "Experienced frontend developer...",
     *   "ai_work_history": [],
     *   "ai_education_history": [],
     *   "ai_languages": ["Arabic", "English"],
     *   "ai_projects": [],
     *   "ai_overall_evaluation": "Strong candidate"
     * }
     * @response 404 { "message": "Job seeker not found" }
     */
    public function showJobSeeker($userId)
    {
        $user = User::find($userId);

        if (! $user || ! $user->isJobSeeker()) {
            return response()->json(['message' => 'Job seeker not found'], 404);
        }

        $profile = JobSeekerProfile::where('user_id', $userId)->first();

        if (! $profile) {
            return response()->json(['message' => 'Job seeker not found'], 404);
        }

        $allowed = [
            // Career
            'current_job_title', 'current_job_status', 'job_level', 'job_types', 'job_roles',
            'years_of_experience', 'education_level', 'expected_salary', 'salary_range_from',
            'salary_range_to', 'is_actively_seeking', 'work_cities', 'city',
            'experience_summary', 'social_links',
            // Structured profile data
            'skills', 'education_history', 'work_experience',
            // AI-derived
            'ats_score', 'ai_skills', 'ai_summary', 'ai_work_history', 'ai_education_history',
            'ai_languages', 'ai_projects', 'ai_social_links', 'ai_overall_evaluation',
            'ai_detected_language', 'ai_analyzed_at',
        ];

        $profileData = collect($profile->toArray())->only($allowed)->toArray();

        return response()->json(array_merge([
            'user_id' => (string) $user->_id,
            'name'    => $user->name,
            'image'   => $profile->image,
        ], $profileData));
    }
}
