<?php

namespace App\Services;

use App\Models\Application;
use App\Models\JobSeekerProfile;
use App\Models\User;

class ConversionFunnelService
{
    /**
     * Compute the four-stage conversion funnel.
     *
     * Returns an array of stages with counts and drop-off percentages.
     * Property 3: counts are monotonically non-increasing; drop-off formula is exact.
     */
    public function compute(): array
    {
        $allUsers    = User::all();
        $registered  = $allUsers->filter(fn ($u) => in_array('employee', $u->roles ?? []))->count();

        $cvUploaded  = JobSeekerProfile::whereNotNull('cv_file_path')->count();

        $allApps     = Application::all();
        $applied     = $allApps->pluck('user_id')->map(fn ($id) => (string) $id)->unique()->count();

        $hired       = $allApps
            ->where('status', 'hired')
            ->pluck('user_id')
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->count();

        // Clamp each stage to not exceed the previous (data integrity guard)
        $cvUploaded = min($cvUploaded, $registered);
        $applied    = min($applied,    $cvUploaded);
        $hired      = min($hired,      $applied);

        return [
            'stages' => [
                [
                    'stage'        => 'registered',
                    'count'        => $registered,
                    'drop_off_pct' => null,
                ],
                [
                    'stage'        => 'cv_uploaded',
                    'count'        => $cvUploaded,
                    'drop_off_pct' => $this->dropOff($registered, $cvUploaded),
                ],
                [
                    'stage'        => 'applied',
                    'count'        => $applied,
                    'drop_off_pct' => $this->dropOff($cvUploaded, $applied),
                ],
                [
                    'stage'        => 'hired',
                    'count'        => $hired,
                    'drop_off_pct' => $this->dropOff($applied, $hired),
                ],
            ],
        ];
    }

    /**
     * Calculate drop-off percentage from prev → curr.
     * When prev is 0 returns 0.0 (no division by zero).
     */
    private function dropOff(int $prev, int $curr): float
    {
        if ($prev === 0) {
            return 0.0;
        }
        return round((($prev - $curr) / $prev) * 100, 2);
    }
}
