<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\CompanyProfile;
use App\Models\DirectOffer;
use App\Models\Employer;
use App\Models\JobPost;
use App\Models\JobSeekerProfile;
use App\Models\User;
use App\Services\DocumentUploadService;
use Illuminate\Console\Command;

/**
 * Removes exactly what the demo seeders created — every document carrying
 * demo_source = 'demo_seed'. Optionally deletes the seeded CVs from Cloudinary.
 *
 * Usage:
 *   php artisan demo:clear                 # remove demo DB documents
 *   php artisan demo:clear --with-cloudinary  # also delete uploaded CVs from Cloudinary
 *   php artisan demo:clear --force         # skip the confirmation prompt
 */
class ClearDemoData extends Command
{
    private const TAG = 'demo_seed';

    protected $signature = 'demo:clear
        {--with-cloudinary : Also delete the uploaded CV files from Cloudinary}
        {--force : Do not ask for confirmation}';

    protected $description = 'Delete all demo-seeded data (demo_source = demo_seed) from the database.';

    public function handle(DocumentUploadService $uploader): int
    {
        if (! $this->option('force') && ! $this->confirm('This deletes ALL demo-seeded data from the current database. Continue?')) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        // Optionally clean up Cloudinary CVs before removing the profiles.
        if ($this->option('with-cloudinary')) {
            $this->deleteCloudinaryCvs($uploader);
        }

        $counts = [
            'applications'   => Application::where('demo_source', self::TAG)->delete(),
            'direct_offers'  => DirectOffer::where('demo_source', self::TAG)->delete(),
            'job_posts'      => JobPost::where('demo_source', self::TAG)->delete(),
            'seeker_profiles'=> JobSeekerProfile::where('demo_source', self::TAG)->delete(),
            'company_profiles'=> CompanyProfile::where('demo_source', self::TAG)->delete(),
            'employers'      => Employer::where('demo_source', self::TAG)->delete(),
            'users'          => User::where('demo_source', self::TAG)->delete(),
        ];

        foreach ($counts as $what => $n) {
            $this->line("  - removed {$n} {$what}");
        }

        $this->info('Demo data cleared.');

        return self::SUCCESS;
    }

    private function deleteCloudinaryCvs(DocumentUploadService $uploader): void
    {
        $profiles = JobSeekerProfile::where('demo_source', self::TAG)
            ->whereNotNull('resume_public_id')
            ->get();

        $deleted = 0;
        foreach ($profiles as $profile) {
            $uploader->delete($profile->resume_public_id, $profile->resume_resource_type ?? 'raw');
            $deleted++;
        }

        $this->line("  - requested Cloudinary deletion of {$deleted} CV file(s)");
    }
}
