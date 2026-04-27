<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Illuminate\Http\Request;

class EmployerSearchController extends Controller
{
    /**
     * Paginated list of actively seeking job seekers with optional filters.
     * GET /api/employer/seekers
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
     * Return a specific job seeker's public profile by user ID.
     * GET /api/employer/seekers/{userId}
     *
     * Excludes sensitive fields: password, email, phone.
     */
    public function show($userId)
    {
        $user = User::find($userId);

        if (!$user || !$user->isJobSeeker()) {
            return response()->json(['message' => 'Job seeker not found'], 404);
        }

        $profile = JobSeekerProfile::where('user_id', $userId)->first();

        if (!$profile) {
            return response()->json(['message' => 'Job seeker not found'], 404);
        }

        $profileData = $profile->toArray();
        unset($profileData['ai_email'], $profileData['ai_phone']);

        return response()->json([
            'seeker' => [
                'user_id' => $user->_id,
                'name'    => $user->name,
                'profile' => $profileData,
            ],
        ]);
    }
}
