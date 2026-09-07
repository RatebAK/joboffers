<?php

namespace Database\Seeders;

use App\Models\JobSeekerProfile;
use App\Models\User;
use Database\Seeders\Support\CvProfileFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds job-seeker users + profiles by SCANNING the CV folder.
 *
 * For each CV file (database/seeders/cvs/*.docx|*.pdf), CvProfileFactory derives
 * a deterministic, role-appropriate profile from the filename (role inferred
 * from tokens like BA / BSA / PM / Scrum / Java / PHP / Hadoop / QA / Mobile).
 * The AI fields are generated locally — the external AI service is NEVER called.
 *
 * If a CV manifest (database/seeders/data/cv-manifest.json) has an entry for the
 * file, the real Cloudinary URL is attached so the CV is downloadable. Otherwise
 * the profile is still seeded (searchable via the AI fields) without a file.
 *
 * Config:
 *   DEMO_SEEKER_LIMIT env var caps how many CVs are seeded (0 / unset = all).
 *
 * Idempotent: keyed by the generated email. Tagged with demo_source.
 */
class JobSeekerProfileSeeder extends Seeder
{
    public function run(): void
    {
        $factory  = new CvProfileFactory();
        $manifest = $this->loadManifest();
        $files    = $this->cvFiles();

        if (empty($files)) {
            $this->command->warn('No CV files found in database/seeders/cvs/. Skipping job seekers.');

            return;
        }

        $limit = (int) env('DEMO_SEEKER_LIMIT', 0);
        if ($limit > 0) {
            $files = array_slice($files, 0, $limit);
        }

        $withCv = 0;
        $withoutCv = 0;
        $roleTally = [];

        foreach ($files as $filename) {
            $s = $factory->fromFilename($filename);
            $roleTally[$s['role_key']] = ($roleTally[$s['role_key']] ?? 0) + 1;

            // 1. Seeker user (idempotent by email).
            $user = User::where('email', $s['email'])->first();
            if (! $user) {
                $user = User::create([
                    'name'              => $s['name'],
                    'email'             => $s['email'],
                    'password'          => Hash::make('Seeker!123'),
                    'roles'             => ['employee'],
                    'email_verified_at' => now(),
                    'demo_source'       => CompanyProfileSeeder::DEMO_TAG,
                ]);
            } else {
                $user->update([
                    'roles' => array_values(array_unique(array_merge($user->roles ?? [], ['employee']))),
                ]);
            }

            // 2. Assemble profile: structured + AI fields + status.
            $data = array_merge(
                $s['profile'],
                $s['ai'],
                [
                    'user_id'               => (string) $user->_id,
                    'analysis_status'       => JobSeekerProfile::ANALYSIS_COMPLETED,
                    'analysis_error'        => null,
                    'analysis_started_at'   => now(),
                    'analysis_completed_at' => now(),
                    'ai_analyzed_at'        => now(),
                    'demo_source'           => CompanyProfileSeeder::DEMO_TAG,
                ]
            );

            // 3. Attach the uploaded CV if present in the manifest.
            if (isset($manifest[$filename]['url'])) {
                $m = $manifest[$filename];
                $data = array_merge($data, [
                    'resume'               => $m['url'],
                    'cv_file_path'         => $m['url'],
                    'resume_public_id'     => $m['public_id'] ?? null,
                    'cv_public_id'         => $m['public_id'] ?? null,
                    'resume_resource_type' => $m['resource_type'] ?? 'raw',
                    'resume_file_type'     => $m['mime_type'] ?? 'application/octet-stream',
                    'resume_original_name' => $m['original_name'] ?? $filename,
                ]);
                $withCv++;
            } else {
                $withoutCv++;
            }

            // 4. Upsert profile.
            $profile = JobSeekerProfile::where('user_id', (string) $user->_id)->first();
            if ($profile) {
                $profile->update($data);
            } else {
                JobSeekerProfile::create($data);
            }
        }

        $this->command->info('Seeded ' . count($files) . " job seekers (with CV file: {$withCv}, without: {$withoutCv}).");
        $this->command->line('  Role breakdown: ' . collect($roleTally)->map(fn ($n, $k) => "{$k}={$n}")->implode(', '));
        if ($withoutCv > 0) {
            $this->command->warn('  Some profiles have no downloadable CV — run `php artisan demo:upload-cvs` first to attach the files.');
        }
    }

    /** @return array<int, string> Filenames (basename) of CVs to seed. */
    private function cvFiles(): array
    {
        $dir = database_path('seeders/cvs');
        if (! is_dir($dir)) {
            return [];
        }

        return collect(glob($dir . DIRECTORY_SEPARATOR . '*'))
            ->filter(fn ($p) => is_file($p))
            ->filter(fn ($p) => in_array(strtolower(pathinfo($p, PATHINFO_EXTENSION)), ['pdf', 'doc', 'docx'], true))
            ->map(fn ($p) => basename($p))
            ->sort()
            ->values()
            ->all();
    }

    /** @return array<string, array<string, mixed>> */
    private function loadManifest(): array
    {
        $path = database_path('seeders/data/cv-manifest.json');
        if (! is_file($path)) {
            $this->command->warn('No cv-manifest.json found — profiles will be seeded without downloadable CV files. Run `php artisan demo:upload-cvs` first to attach real CVs.');

            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
