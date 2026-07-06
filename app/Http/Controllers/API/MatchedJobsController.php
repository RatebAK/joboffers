<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\JobPost;
use App\Models\JobSeekerProfile;
use Illuminate\Http\Request;

class MatchedJobsController extends Controller
{
    /**
     * Matched jobs
     *
     * Returns a paginated list of active job posts ranked by match_score for the authenticated job seeker.
     * Match score is computed from skill overlap (+2 each), location match (+3), job type match (+2),
     * and experience level match (+2). Posts the seeker has already applied to are excluded.
     *
     * @queryParam min_score integer Only return posts with match_score >= this value. Example: 4
     * @queryParam page integer Page number. Example: 1
     *
     * @response 200 {
     *   "data": [
     *     {
     *       "id": "...", "title": "Senior PHP Developer", "company_name": "Acme",
     *       "location": "Beirut", "job_type": "full_time", "match_score": 9
     *     }
     *   ],
     *   "current_page": 1, "per_page": 10, "total": 5, "total_pages": 1, "next_page": null, "prev_page": null
     * }
     */
    public function index(Request $request)
    {
        $seeker   = $request->user();
        $seekerId = (string) $seeker->_id;
        $minScore = $request->query('min_score') !== null ? (int) $request->query('min_score') : null;
        $perPage  = 10;
        $page     = max(1, (int) $request->query('page', 1));

        // IDs of jobs already applied to
        $appliedIds = Application::where('user_id', $seekerId)
            ->pluck('job_post_id')
            ->map(fn($id) => (string) $id)
            ->toArray();

        // Load seeker profile
        $profile = JobSeekerProfile::where('user_id', $seekerId)->first();

        // Fetch all active, non-applied posts
        $query = JobPost::where('is_active', true);
        if (!empty($appliedIds)) {
            $query->whereNotIn('_id', $appliedIds);
        }
        $posts = $query->orderBy('created_at', 'desc')->get();

        if (!$profile) {
            // No profile: return posts with match_score 0
            $scored = $posts->map(fn($post) => array_merge($post->toArray(), ['match_score' => 0]));
        } else {
            $seekerSkills = $this->collectSeekerSkills($profile);

            $scored = $posts->map(function ($post) use ($profile, $seekerSkills) {
                $score = $this->computeMatchScore($post, $profile, $seekerSkills);
                return array_merge($post->toArray(), ['match_score' => $score]);
            });

            // Sort by match_score desc (created_at desc is already the base order)
            $scored = $scored->sortByDesc('match_score')->values();
        }

        // Apply min_score filter
        if ($minScore !== null) {
            $scored = $scored->filter(fn($item) => $item['match_score'] >= $minScore)->values();
        }

        // Manual pagination
        $total      = $scored->count();
        $totalPages = (int) ceil($total / $perPage);
        $offset     = ($page - 1) * $perPage;
        $items      = $scored->slice($offset, $perPage)->values();

        return response()->json([
            'data'        => $items,
            'current_page'=> $page,
            'per_page'    => $perPage,
            'total'       => $total,
            'total_pages' => $totalPages,
            'next_page'   => $page < $totalPages ? $page + 1 : null,
            'prev_page'   => $page > 1 ? $page - 1 : null,
        ]);
    }

    private function collectSeekerSkills(JobSeekerProfile $profile): array
    {
        $skills = array_map('strtolower', (array) ($profile->ai_skills ?? []));
        foreach ((array) ($profile->skills ?? []) as $s) {
            if (is_array($s) && isset($s['name'])) {
                $skills[] = strtolower($s['name']);
            } elseif (is_string($s)) {
                $skills[] = strtolower($s);
            }
        }
        return array_unique(array_filter($skills));
    }

    private function computeMatchScore(JobPost $post, JobSeekerProfile $profile, array $seekerSkills): int
    {
        $score = 0;

        // Skill overlap: +2 per matching skill
        $postSkills = array_unique(array_map('strtolower', array_merge(
            (array) ($post->roles ?? []),
            (array) ($post->tags ?? [])
        )));
        foreach ($postSkills as $skill) {
            if ($skill !== '' && in_array($skill, $seekerSkills)) {
                $score += 2;
            }
        }

        // Location bonus: +3
        $postLocation   = strtolower((string) ($post->city ?? $post->location ?? ''));
        $seekerLocation = strtolower((string) ($profile->ai_location ?? $profile->location ?? ''));
        if ($postLocation !== '' && $seekerLocation !== '') {
            if (str_contains($seekerLocation, $postLocation) || str_contains($postLocation, $seekerLocation)) {
                $score += 3;
            }
        }

        // Job type bonus: +2
        $postType    = strtolower((string) ($post->job_type ?? ''));
        $seekerTypes = array_map('strtolower', (array) ($profile->job_types ?? []));
        if ($postType !== '' && in_array($postType, $seekerTypes)) {
            $score += 2;
        }

        // Experience level bonus: +2
        $postLevel   = strtolower((string) ($post->experience_level ?? ''));
        $seekerLevel = strtolower((string) ($profile->job_level ?? ''));
        if ($postLevel !== '' && $seekerLevel !== '' && $postLevel === $seekerLevel) {
            $score += 2;
        }

        return $score;
    }
}
