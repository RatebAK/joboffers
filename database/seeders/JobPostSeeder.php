<?php

namespace Database\Seeders;

use App\Models\CompanyProfile;
use App\Models\JobPost;
use Illuminate\Database\Seeder;

/**
 * Seeds active job posts across the demo companies.
 *
 * Each post is denormalised with company_name + company_logo (as the app does),
 * has is_active = true (required to appear on public /api/jobs), a JOB-xxx
 * job_id, and a created_at so the "newest first" ordering looks natural.
 *
 * Idempotent: keyed by job_id. Tagged with demo_source for teardown.
 */
class JobPostSeeder extends Seeder
{
    /**
     * Templates matched to the CV talent pool (BA / BSA / PM / Scrum / Java /
     * Full-Stack / PHP / Hadoop / QA / Mobile) so seeded jobs fit the seekers.
     */
    private array $templates = [
        ['title' => 'Business Analyst',              'category' => 'Product & Management',   'roles' => ['Business Analyst'],                    'level' => 'mid',    'type' => 'full_time', 'mode' => 'hybrid',  'exp' => 3, 'sal' => [1000, 1800], 'tags' => ['requirements', 'sql', 'jira', 'agile']],
        ['title' => 'Senior Business Systems Analyst','category' => 'Product & Management',   'roles' => ['Business Systems Analyst', 'Business Analyst'], 'level' => 'senior', 'type' => 'full_time', 'mode' => 'hybrid', 'exp' => 6, 'sal' => [1800, 2800], 'tags' => ['bsa', 'uml', 'uat', 'process modeling']],
        ['title' => 'Project Manager',               'category' => 'Product & Management',   'roles' => ['Project Manager', 'Program Manager'],  'level' => 'senior', 'type' => 'full_time', 'mode' => 'hybrid',  'exp' => 7, 'sal' => [2200, 3800], 'tags' => ['pmp', 'agile', 'risk management', 'ms project']],
        ['title' => 'Scrum Master',                  'category' => 'Product & Management',   'roles' => ['Scrum Master', 'Agile Coach'],         'level' => 'senior', 'type' => 'full_time', 'mode' => 'remote',  'exp' => 5, 'sal' => [1800, 3000], 'tags' => ['scrum', 'agile', 'jira', 'safe']],
        ['title' => 'Senior Java Developer',         'category' => 'Information Technology',  'roles' => ['Java Developer', 'Backend Developer'], 'level' => 'senior', 'type' => 'full_time', 'mode' => 'remote',  'exp' => 6, 'sal' => [2000, 3400], 'tags' => ['java', 'spring boot', 'microservices', 'rest']],
        ['title' => 'Full Stack Java Developer',     'category' => 'Information Technology',  'roles' => ['Full Stack Developer', 'Java Developer'],'level' => 'senior','type' => 'full_time','mode' => 'remote',  'exp' => 5, 'sal' => [1800, 3200], 'tags' => ['java', 'react', 'angular', 'spring boot']],
        ['title' => 'Senior PHP Developer',          'category' => 'Information Technology',  'roles' => ['PHP Developer', 'Backend Developer'],  'level' => 'senior', 'type' => 'full_time', 'mode' => 'remote',  'exp' => 5, 'sal' => [1500, 2600], 'tags' => ['php', 'laravel', 'mysql', 'rest']],
        ['title' => 'Hadoop / Big Data Developer',   'category' => 'Data & AI',              'roles' => ['Data Engineer', 'Hadoop Developer'],   'level' => 'senior', 'type' => 'full_time', 'mode' => 'remote',  'exp' => 6, 'sal' => [2200, 3600], 'tags' => ['hadoop', 'spark', 'hive', 'kafka']],
        ['title' => 'QA Engineer',                   'category' => 'Information Technology',  'roles' => ['QA Engineer'],                         'level' => 'mid',    'type' => 'full_time', 'mode' => 'on_site', 'exp' => 3, 'sal' => [1000, 1800], 'tags' => ['selenium', 'automation', 'api testing', 'jira']],
        ['title' => 'Mobile Developer',              'category' => 'Information Technology',  'roles' => ['Mobile Developer'],                    'level' => 'mid',    'type' => 'full_time', 'mode' => 'hybrid',  'exp' => 3, 'sal' => [1200, 2200], 'tags' => ['android', 'kotlin', 'ios', 'swift']],
        ['title' => 'Technical Program Manager',     'category' => 'Product & Management',   'roles' => ['Program Manager', 'Project Manager'],  'level' => 'senior', 'type' => 'full_time', 'mode' => 'hybrid',  'exp' => 8, 'sal' => [2600, 4200], 'tags' => ['program management', 'stakeholders', 'delivery']],
        ['title' => 'Healthcare Business Analyst',   'category' => 'Product & Management',   'roles' => ['Business Analyst'],                    'level' => 'mid',    'type' => 'full_time', 'mode' => 'on_site', 'exp' => 4, 'sal' => [1200, 2000], 'tags' => ['healthcare', 'hl7', 'requirements', 'uat']],
    ];

    public function run(): void
    {
        $companies = CompanyProfile::where('demo_source', CompanyProfileSeeder::DEMO_TAG)->get();

        if ($companies->isEmpty()) {
            $this->command->warn('No demo companies found — run CompanyProfileSeeder first. Skipping job posts.');

            return;
        }

        $counter = 1;
        $created = 0;

        // ~5 posts per company => ~50 jobs for 10 companies.
        foreach ($companies as $company) {
            $picks = collect($this->templates)->shuffle()->take(5);

            foreach ($picks as $t) {
                $jobId = sprintf('JOB-%03d', $counter);
                $counter++;

                if (JobPost::where('job_id', $jobId)->exists()) {
                    continue;
                }

                JobPost::create([
                    'job_id'                => $jobId,
                    'employer_id'           => (string) $company->employer_id,
                    'company_profile_id'    => (string) $company->_id,
                    'company_name'          => $company->name,
                    'company_logo'          => $company->logo,

                    'communication_method'  => JobPost::COMM_BY_FORSA,
                    'communication_value'   => null,

                    'title'                 => $t['title'],
                    'roles'                 => $t['roles'],
                    'category'              => $t['category'],
                    'portfolio_required'    => false,
                    'cover_letter_required' => false,
                    'gender'                => 'no_preference',
                    'education_level'       => 'bachelor',
                    'job_level'             => $t['level'],
                    'experience_years'      => $t['exp'],
                    'languages'             => ['Arabic', 'English'],

                    'vacancies'             => 1,
                    'job_type'              => $t['type'],
                    'work_mode'             => $t['mode'],
                    'city'                  => $company->city,
                    'address'               => $company->city . ', ' . ($company->country ?? 'Syria'),
                    'salary_from'           => $t['sal'][0],
                    'salary_to'             => $t['sal'][1],
                    'currency'              => 'USD',
                    'display_salary'        => true,
                    'incentives'            => 'Health insurance, annual bonus.',

                    'description'           => "We are hiring a {$t['title']} to join {$company->name}. You will work with a collaborative team on impactful products.",
                    'requirements'          => "Proven experience as a {$t['title']}. Strong fundamentals and good communication skills.",
                    'questions'             => [],

                    'tags'                  => $t['tags'],
                    'is_active'             => true,
                    'expires_at'            => now()->addDays(45),
                    'created_at'            => now()->subDays(rand(0, 30)),
                    'demo_source'           => CompanyProfileSeeder::DEMO_TAG,
                ]);

                $created++;
            }
        }

        $this->command->info("Seeded {$created} active job posts across {$companies->count()} companies.");
    }
}
