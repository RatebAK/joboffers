<?php

namespace App\Services;

use App\Models\Application;
use App\Models\JobPost;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Illuminate\Support\Collection;

class ChurnReportService
{
    /**
     * Return employers who have no job posts within the last $windowDays days,
     * or have never posted at all.
     */
    public function getChurnedEmployers(int $windowDays): Collection
    {
        $cutoff = now()->subDays($windowDays);

        // Load all employer users
        $allUsers    = User::all();
        $employers   = $allUsers->filter(fn ($u) => in_array('employer', $u->roles ?? []));
        $allJobPosts = JobPost::all();

        return $employers->map(function (User $user) use ($allJobPosts, $cutoff) {
            $userId   = (string) $user->_id;
            $myPosts  = $allJobPosts->filter(fn ($p) => (string) $p->employer_id === $userId);

            $lastPost     = $myPosts->sortByDesc('created_at')->first();
            $lastPostDate = $lastPost ? $lastPost->created_at : null;

            // Active if they have a post created after the cutoff
            $isActive = $myPosts->contains(
                fn ($p) => $p->created_at && $p->created_at->greaterThan($cutoff)
            );

            if ($isActive) {
                return null;
            }

            return [
                'user_id'        => $userId,
                'name'           => $user->name,
                'email'          => $user->email,
                'registered_at'  => $user->created_at?->toISOString(),
                'last_post_date' => $lastPostDate?->toISOString(),
                'total_posts'    => $myPosts->count(),
            ];
        })->filter()->values();
    }

    /**
     * Return job seekers who have a CV on file but zero applications.
     */
    public function getChurnedSeekers(): Collection
    {
        $allUsers        = User::all();
        $seekers         = $allUsers->filter(fn ($u) => in_array('employee', $u->roles ?? []));
        $allProfiles     = JobSeekerProfile::whereNotNull('cv_file_path')->get();
        $allApplications = Application::all();

        // Index profiles by user_id for O(1) lookup
        $profilesByUser = $allProfiles->keyBy(fn ($p) => (string) $p->user_id);

        // Index application user_ids as a set
        $applicantIds = $allApplications->pluck('user_id')->map(fn ($id) => (string) $id)->flip();

        return $seekers->map(function (User $user) use ($profilesByUser, $applicantIds) {
            $userId  = (string) $user->_id;
            $profile = $profilesByUser->get($userId);

            if (! $profile) {
                return null; // No CV — not churned by our definition
            }

            if (isset($applicantIds[$userId])) {
                return null; // Has at least one application — active
            }

            return [
                'user_id'        => $userId,
                'name'           => $user->name,
                'email'          => $user->email,
                'registered_at'  => $user->created_at?->toISOString(),
                'cv_uploaded_at' => $profile->ai_analyzed_at?->toISOString()
                                    ?? $profile->updated_at?->toISOString(),
                'ats_score'      => $profile->ats_score,
            ];
        })->filter()->values();
    }

    /**
     * Serialize both collections to a CSV string.
     */
    public function toCsv(Collection $employers, Collection $seekers): string
    {
        $lines = [];

        $lines[] = 'CHURNED EMPLOYERS';
        $lines[] = 'user_id,name,email,registered_at,last_post_date,total_posts';
        foreach ($employers as $row) {
            $lines[] = implode(',', array_map(fn ($v) => '"' . str_replace('"', '""', (string) ($v ?? '')) . '"', $row));
        }

        $lines[] = '';
        $lines[] = 'CHURNED JOB SEEKERS';
        $lines[] = 'user_id,name,email,registered_at,cv_uploaded_at,ats_score';
        foreach ($seekers as $row) {
            $lines[] = implode(',', array_map(fn ($v) => '"' . str_replace('"', '""', (string) ($v ?? '')) . '"', $row));
        }

        return implode("\n", $lines);
    }
}
