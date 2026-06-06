<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Illuminate\Http\Request;

class UserSearchController extends Controller
{
    /**
     * Search users (talents/workers)
     *
     * General-purpose search for job seekers with comprehensive filtering. Searches profiles and returns matching candidates.
     *
     * @queryParam search string General search term matching name, current title, or summary. Example: React Developer
     * @queryParam skills string Comma-separated list of skills (partial match, case-insensitive). Example: React,TypeScript,Node.js
     * @queryParam min_experience integer Minimum years of experience. Example: 3
     * @queryParam max_experience integer Maximum years of experience. Example: 10
     * @queryParam min_ats_score integer Minimum ATS score (0-100). Example: 70
     * @queryParam max_ats_score integer Maximum ATS score (0-100). Example: 95
     * @queryParam location string Location search (partial match). Example: Beirut
     * @queryParam job_level string Job level filter (entry, junior, mid, senior, lead, executive). Example: senior
     * @queryParam actively_seeking boolean Filter by active job seeking status. Example: true
     * @queryParam per_page integer Number of results per page (max 100). Defaults to 15. Example: 10
     * @queryParam page integer Page number. Example: 1
     *
     * @response 200 {
     *   "data": [
     *     {
     *       "id": "664f1a2b3c4d5e6f7a8b9c0d",
     *       "user_id": "664f1a2b3c4d5e6f7a8b9c0e",
     *       "name": "Jane Smith",
     *       "current_job_title": "Senior Frontend Developer",
     *       "ai_summary": "Experienced frontend developer with 5 years...",
     *       "ai_skills": ["React", "TypeScript", "Node.js"],
     *       "ai_location": "Beirut, Lebanon",
     *       "ats_score": 85,
     *       "years_of_experience": 5,
     *       "job_level": "senior",
     *       "is_actively_seeking": true
     *     }
     *   ],
     *   "current_page": 1,
     *   "per_page": 15,
     *   "total": 42,
     *   "total_pages": 3,
     *   "next_page": 2,
     *   "prev_page": null
     * }
     */
    public function index(Request $request)
    {
        $query = JobSeekerProfile::query();

        // General search across name, current title, and summary
        if ($search = $request->query('search')) {
            $regex = new \MongoDB\BSON\Regex(preg_quote($search, '/'), 'i');
            $query->where(function ($q) use ($regex) {
                $q->where('ai_full_name', 'regex', $regex)
                  ->orWhere('current_job_title', 'regex', $regex)
                  ->orWhere('ai_summary', 'regex', $regex);
            });
        }

        // Skills filter: partial match on any skill (OR logic)
        if ($skills = $request->query('skills')) {
            $skillList = array_filter(array_map('trim', explode(',', $skills)));
            
            if (!empty($skillList)) {
                $query->where(function ($q) use ($skillList) {
                    foreach ($skillList as $skill) {
                        $q->orWhere('ai_skills', 'regex', new \MongoDB\BSON\Regex(preg_quote($skill, '/'), 'i'));
                    }
                });
            }
        }

        // Experience range filters
        if ($minExp = $request->query('min_experience')) {
            $query->where('years_of_experience', '>=', (int) $minExp);
        }

        if ($maxExp = $request->query('max_experience')) {
            $query->where('years_of_experience', '<=', (int) $maxExp);
        }

        // ATS score range filters
        if ($minAts = $request->query('min_ats_score')) {
            $query->where('ats_score', '>=', (int) $minAts);
        }

        if ($maxAts = $request->query('max_ats_score')) {
            $query->where('ats_score', '<=', (int) $maxAts);
        }

        // Location filter
        if ($location = $request->query('location')) {
            $query->where('ai_location', 'regex', new \MongoDB\BSON\Regex(preg_quote($location, '/'), 'i'));
        }

        // Job level filter
        if ($jobLevel = $request->query('job_level')) {
            $query->where('job_level', 'regex', new \MongoDB\BSON\Regex('^' . preg_quote($jobLevel, '/') . '$', 'i'));
        }

        // Active seeking filter
        if ($request->has('actively_seeking')) {
            $query->where('is_actively_seeking', filter_var($request->query('actively_seeking'), FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = min((int) ($request->query('per_page', 15)), 100);
        $paginator = $query->orderBy('ats_score', 'desc')->paginate($perPage);

        // Enrich with user name
        $paginator->getCollection()->transform(function ($profile) {
            $data = $profile->toArray();
            $user = User::find($profile->user_id);
            $data['name'] = $user ? $user->name : $profile->ai_full_name;
            
            // Remove sensitive fields
            unset($data['ai_email'], $data['ai_phone'], $data['ai_phone_number']);
            
            return $data;
        });

        return response()->json([
            'data'         => $paginator->items(),
            'current_page' => $paginator->currentPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
            'total_pages'  => $paginator->lastPage(),
            'next_page'    => $paginator->hasMorePages() ? $paginator->currentPage() + 1 : null,
            'prev_page'    => $paginator->currentPage() > 1 ? $paginator->currentPage() - 1 : null,
        ]);
    }
}
