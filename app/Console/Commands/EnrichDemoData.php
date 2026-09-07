<?php

namespace App\Console\Commands;

use App\Models\CompanyProfile;
use App\Models\JobSeekerProfile;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Backfills visual + social polish onto already-seeded demo data:
 *   - Job seekers: a deterministic avatar image (per-person, stable).
 *   - Companies: a logo + a cover image, plus a set of realistic reviews and
 *     the derived rating aggregates (rating, review_count, would_recommend,
 *     ceo_performance, category_ratings).
 *
 * Uses free, no-auth image services that return real, hotlinkable URLs:
 *   - Avatars:  https://i.pravatar.cc/300?u=<seed>   (photo-style faces)
 *   - Logos:    https://ui-avatars.com/api/...        (initials on colour)
 *   - Covers:   https://picsum.photos/seed/<seed>/... (real photos)
 *
 * Only touches documents tagged demo_source='demo_seed'. Idempotent — safe to
 * re-run; by default it skips records that already have an image/reviews unless
 * you pass --force.
 *
 * Usage:
 *   php artisan demo:enrich
 *   php artisan demo:enrich --force   # overwrite existing images/reviews too
 */
class EnrichDemoData extends Command
{
    private const TAG = 'demo_seed';

    protected $signature = 'demo:enrich {--force : Overwrite existing images/reviews}';

    protected $description = 'Add avatars to seekers and logos/covers/reviews/ratings to companies (demo data only).';

    private array $reviewTitles = [
        'Great place to grow', 'Solid engineering culture', 'Good work-life balance',
        'Fast-paced but rewarding', 'Supportive management', 'Learned a lot here',
        'Competitive pay', 'Strong team spirit', 'Room for improvement', 'Would recommend',
    ];

    private array $reviewBodies = [
        'Leadership is transparent and the projects are genuinely interesting.',
        'Good benefits and a collaborative team. Onboarding could be smoother.',
        'Plenty of learning opportunities and mentorship from senior staff.',
        'Flexible hours and remote-friendly. Delivery pressure can be high at times.',
        'Management listens to feedback and invests in the team.',
        'Clear career path and regular performance reviews.',
    ];

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $this->enrichSeekers($force);
        $this->enrichCompanies($force);

        $this->info('Enrichment complete.');

        return self::SUCCESS;
    }

    private function enrichSeekers(bool $force): void
    {
        $query = JobSeekerProfile::where('demo_source', self::TAG);
        $total = (clone $query)->count();
        $this->info("Enriching {$total} seeker profiles with avatars...");

        $updated = 0;
        $query->chunk(50, function ($profiles) use ($force, &$updated) {
            foreach ($profiles as $profile) {
                if (! $force && ! empty($profile->image)) {
                    continue;
                }

                $seed = $profile->user_id ?: (string) $profile->_id;
                $profile->update([
                    'image'           => 'https://i.pravatar.cc/300?u=' . urlencode($seed),
                    'image_public_id' => null, // external URL, not a Cloudinary asset
                ]);
                $updated++;
            }
        });

        $this->line("  Avatars set on {$updated} seekers.");
    }

    private function enrichCompanies(bool $force): void
    {
        $companies = CompanyProfile::where('demo_source', self::TAG)->get();
        $this->info("Enriching {$companies->count()} companies with logos, covers, and reviews...");

        $updated = 0;
        foreach ($companies as $company) {
            $data = [];

            // Logo (initials on a coloured tile) + cover photo.
            if ($force || blank($company->logo) || str_contains((string) $company->logo, 'placehold.co')) {
                $data['logo'] = 'https://ui-avatars.com/api/?background=random&size=256&bold=true&name='
                    . urlencode($company->name);
            }
            if ($force || blank($company->cover_image)) {
                $data['cover_image'] = 'https://picsum.photos/seed/'
                    . urlencode(Str::slug($company->name)) . '/1200/400';
            }

            // Reviews + rating aggregates.
            if ($force || blank($company->reviews) || ($company->review_count ?? 0) === 0) {
                $reviews = $this->buildReviews($company->name);
                $data = array_merge($data, $this->aggregate($reviews), ['reviews' => $reviews]);
            }

            if (! empty($data)) {
                $company->update($data);
                $updated++;
            }
        }

        $this->line("  Updated {$updated} companies.");
    }

    /** @return array<int, array<string, mixed>> */
    private function buildReviews(string $companyName): array
    {
        // Deterministic count/values per company so re-runs are stable.
        mt_srand(crc32($companyName));
        $count = mt_rand(3, 6);

        $authors = ['Former Employee', 'Current Employee', 'Software Engineer', 'Business Analyst', 'Project Manager', 'QA Engineer'];
        $reviews = [];

        for ($i = 0; $i < $count; $i++) {
            $rating = mt_rand(3, 5);
            $reviews[] = [
                'id'              => (string) Str::uuid(),
                'author'         => $authors[mt_rand(0, count($authors) - 1)],
                'rating'         => $rating,
                'title'          => $this->reviewTitles[mt_rand(0, count($this->reviewTitles) - 1)],
                'body'           => $this->reviewBodies[mt_rand(0, count($this->reviewBodies) - 1)],
                'recommends'     => $rating >= 4,
                'category_ratings' => [
                    'compensation' => mt_rand(3, 5),
                    'culture'      => mt_rand(3, 5),
                    'work_life'    => mt_rand(3, 5),
                    'diversity'    => mt_rand(3, 5),
                    'management'   => mt_rand(3, 5),
                ],
                'created_at'     => now()->subDays(mt_rand(5, 300))->toIso8601String(),
            ];
        }

        mt_srand();

        return $reviews;
    }

    /**
     * Derive the company-level aggregates from its reviews.
     *
     * @param  array<int, array<string, mixed>>  $reviews
     * @return array<string, mixed>
     */
    private function aggregate(array $reviews): array
    {
        $count = count($reviews);
        if ($count === 0) {
            return [];
        }

        $avg = fn (array $vals) => round(array_sum($vals) / max(count($vals), 1), 1);

        $ratings     = array_column($reviews, 'rating');
        $recommends  = array_filter($reviews, fn ($r) => $r['recommends'] ?? false);

        $catKeys = ['compensation', 'culture', 'work_life', 'diversity', 'management'];
        $catRatings = [];
        foreach ($catKeys as $k) {
            $catRatings[$k] = $avg(array_map(fn ($r) => $r['category_ratings'][$k] ?? 0, $reviews));
        }

        return [
            'rating'          => $avg($ratings),
            'review_count'    => $count,
            'would_recommend' => (int) round(count($recommends) / $count * 100),
            'ceo_performance' => mt_rand(60, 95),
            'category_ratings' => $catRatings,
        ];
    }
}
