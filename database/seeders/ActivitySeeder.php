<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\DirectOffer;
use App\Models\JobPost;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds realistic activity so analytics / admin reports are non-empty:
 *   - Applications: demo seekers apply to demo job posts (varied statuses).
 *   - Direct offers: employers offer seekers; some accepted (auto-creates an
 *     application, mirroring DirectOfferController::accept), some declined.
 *
 * Idempotent: applications keyed by (user_id, job_post_id); offers by
 * (employer_id, job_seeker_id, job_post_id). Tagged with demo_source.
 */
class ActivitySeeder extends Seeder
{
    private array $statuses = ['pending', 'reviewed', 'shortlisted', 'rejected', 'accepted'];

    public function run(): void
    {
        // Drive off JobSeekerProfile (every demo seeker has one, tagged with
        // demo_source). Matching users by array `roles` via a plain where() is
        // unreliable on the Mongo driver, so we go through the profiles instead.
        $profiles = JobSeekerProfile::where('demo_source', CompanyProfileSeeder::DEMO_TAG)->get();

        $jobs = JobPost::where('demo_source', CompanyProfileSeeder::DEMO_TAG)->get();

        if ($profiles->isEmpty() || $jobs->isEmpty()) {
            $this->command->warn('Need demo seekers and demo jobs before seeding activity. Skipping.');

            return;
        }

        $applications = 0;
        $offers = 0;

        foreach ($profiles as $profile) {
            $seeker = User::find($profile->user_id);
            if (! $seeker) {
                continue;
            }

            // Each seeker applies to 2-3 random jobs.
            foreach ($jobs->shuffle()->take(rand(2, 3)) as $job) {
                $exists = Application::where('user_id', (string) $seeker->_id)
                    ->where('job_post_id', (string) $job->_id)
                    ->exists();

                if (! $exists) {
                    Application::create([
                        'user_id'             => (string) $seeker->_id,
                        'job_post_id'         => (string) $job->_id,
                        'resume'              => $profile->cv_file_path ?? $profile->resume ?? null,
                        'cover_letter'        => 'I am excited to apply for the ' . $job->title . ' role.',
                        'status'              => $this->statuses[array_rand($this->statuses)],
                        'applied_at'          => now()->subDays(rand(0, 20)),
                        'years_of_experience' => $profile->years_of_experience ?? null,
                        'expected_salary'     => $profile->expected_salary ?? null,
                        'answers'             => [],
                        'demo_source'         => CompanyProfileSeeder::DEMO_TAG,
                    ]);
                    $applications++;
                }
            }

            // Give each seeker one direct offer from a random job's employer.
            $offerJob = $jobs->random();
            $offerExists = DirectOffer::where('employer_id', (string) $offerJob->employer_id)
                ->where('job_seeker_id', (string) $seeker->_id)
                ->where('job_post_id', (string) $offerJob->_id)
                ->exists();

            if (! $offerExists) {
                $status = ['pending', 'accepted', 'declined'][array_rand([0, 1, 2])];

                DirectOffer::create([
                    'employer_id'   => (string) $offerJob->employer_id,
                    'job_seeker_id' => (string) $seeker->_id,
                    'job_post_id'   => (string) $offerJob->_id,
                    'message'       => "We'd love to have you join us for the {$offerJob->title} role at {$offerJob->company_name}.",
                    'status'        => $status,
                    'demo_source'   => CompanyProfileSeeder::DEMO_TAG,
                ]);
                $offers++;

                // Accepting an offer auto-creates an application (as the controller does).
                if ($status === 'accepted') {
                    $already = Application::where('user_id', (string) $seeker->_id)
                        ->where('job_post_id', (string) $offerJob->_id)
                        ->exists();

                    if (! $already) {
                        Application::create([
                            'user_id'      => (string) $seeker->_id,
                            'job_post_id'  => (string) $offerJob->_id,
                            'resume'       => $profile->cv_file_path ?? $profile->resume ?? null,
                            'cover_letter' => $profile->default_cover_letter ?? null,
                            'status'       => 'pending',
                            'applied_at'   => now(),
                            'demo_source'  => CompanyProfileSeeder::DEMO_TAG,
                        ]);
                        $applications++;
                    }
                }
            }
        }

        $this->command->info("Seeded {$applications} applications and {$offers} direct offers.");
    }
}
