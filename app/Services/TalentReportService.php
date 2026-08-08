<?php

namespace App\Services;

use App\Models\JobSeekerProfile;

class TalentReportService
{
    // Fields that must NEVER appear in any response (Property 7)
    private const PII_FIELDS = [
        'name', 'email', 'phone', 'user_id',
        'ai_email', 'ai_phone', 'ai_full_name', 'ai_location',
    ];

    /**
     * Compute aggregated talent market data.
     *
     * @param int         $limit    Top N skills to return (default 20)
     * @param string|null $industry Filter by job_roles value
     *
     * @throws \InvalidArgumentException when fewer than 5 profiles match
     */
    public function compute(int $limit = 20, ?string $industry = null): array
    {
        // Load all profiles and filter in PHP — consistent with existing adminAnalytics pattern
        // (avoids MongoDB array-field query syntax variations across driver versions)
        $all = JobSeekerProfile::all();

        $profiles = $industry
            ? $all->filter(fn ($p) => in_array($industry, (array) ($p->job_roles ?? [])))
            : $all;

        if ($profiles->count() < 5) {
            throw new \InvalidArgumentException('Insufficient data for anonymized report');
        }

        // Aggregate skills
        $skillCounts = [];
        foreach ($profiles as $profile) {
            foreach ((array) ($profile->ai_skills ?? []) as $skill) {
                if (! empty($skill)) {
                    $normalized = is_string($skill) ? trim($skill) : (string) $skill;
                    $skillCounts[$normalized] = ($skillCounts[$normalized] ?? 0) + 1;
                }
            }
        }
        arsort($skillCounts);
        $topSkills = array_slice(
            array_map(fn ($skill, $count) => ['skill' => $skill, 'count' => $count], array_keys($skillCounts), $skillCounts),
            0, $limit
        );

        // ATS stats
        $scores = $profiles
            ->whereNotNull('ats_score')
            ->pluck('ats_score')
            ->map(fn ($s) => (float) $s)
            ->sort()
            ->values()
            ->toArray();

        $atsStats = $this->computeAtsStats($scores);

        return [
            'profile_count' => $profiles->count(),
            'top_skills'    => array_values($topSkills),
            'ats_stats'     => $atsStats,
        ];
    }

    /**
     * Serialize report data to CSV string (no PII fields).
     */
    public function toCsv(array $data): string
    {
        $lines = [];

        $lines[] = 'TALENT MARKET REPORT';
        $lines[] = 'Profile Count: ' . ($data['profile_count'] ?? 0);
        $lines[] = '';

        // ATS stats
        $lines[] = 'ATS Statistics';
        $ats = $data['ats_stats'] ?? [];
        $lines[] = 'average,median,minimum,maximum';
        $lines[] = implode(',', [
            $ats['average'] ?? 0,
            $ats['median']  ?? 0,
            $ats['minimum'] ?? 0,
            $ats['maximum'] ?? 0,
        ]);

        $lines[] = '';
        $lines[] = 'Top Skills';
        $lines[] = 'skill,count';
        foreach ($data['top_skills'] ?? [] as $row) {
            $lines[] = '"' . str_replace('"', '""', $row['skill']) . '",' . $row['count'];
        }

        return implode("\n", $lines);
    }

    // ── Private helpers ──────────────────────────────────────────────

    private function computeAtsStats(array $scores): array
    {
        if (empty($scores)) {
            return ['average' => null, 'median' => null, 'minimum' => null, 'maximum' => null];
        }

        $count   = count($scores);
        $average = round(array_sum($scores) / $count, 2);
        $minimum = $scores[0];
        $maximum = $scores[$count - 1];

        if ($count % 2 === 1) {
            $median = $scores[(int) floor($count / 2)];
        } else {
            $median = ($scores[$count / 2 - 1] + $scores[$count / 2]) / 2;
        }

        return [
            'average' => $average,
            'median'  => round($median, 2),
            'minimum' => $minimum,
            'maximum' => $maximum,
        ];
    }
}
