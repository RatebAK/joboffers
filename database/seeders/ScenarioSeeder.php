<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\CompanyProfile;
use App\Models\DirectOffer;
use App\Models\Employer;
use App\Models\JobPost;
use App\Models\JobSeekerProfile;
use App\Models\Meeting;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates a small set of NAMED, KNOWN-STATE accounts wired into every scenario
 * the platform supports, so the whole flow can be tested by hand:
 *
 *   - 1 admin
 *   - 1 employer (approved) with a company + several job posts
 *   - 1 pending employer (awaiting admin approval)
 *   - job seekers, each demonstrating a specific state:
 *       * applications in every status (pending / reviewed / accepted / rejected)
 *       * direct offers in every status (pending / accepted / declined)
 *       * meetings in every status (pending / accepted / declined / completed /
 *         cancelled / rescheduled)
 *       * notifications (read + unread)
 *
 * All accounts use fixed emails/passwords printed at the end. Tagged
 * demo_source='demo_seed' so `php artisan demo:clear` removes them too.
 * Idempotent: keyed by email / natural keys.
 */
class ScenarioSeeder extends Seeder
{
    private const PW_ADMIN    = 'Admin!123';
    private const PW_EMPLOYER = 'Employer!123';
    private const PW_SEEKER   = 'Seeker!123';
    private const TAG         = CompanyProfileSeeder::DEMO_TAG;

    /** @var array<string, array<string,string>> Collected for the final summary. */
    private array $summary = [];

    public function run(): void
    {
        // ── Admin ────────────────────────────────────────────────
        $admin = $this->user('scenario.admin@test.demo', 'Scenario Admin', ['admin'], self::PW_ADMIN);
        $this->summary['Admin'] = ['email' => $admin->email, 'password' => self::PW_ADMIN, 'note' => 'Approves employers, sees all analytics/reports'];

        // ── Approved employer + company + jobs ───────────────────
        $employer = $this->user('scenario.employer@test.demo', 'Scenario Employer', ['employer'], self::PW_EMPLOYER, ['is_employer' => true]);
        $this->approveEmployerRecord($employer);
        $company = $this->company($employer, 'Scenario Tech Co', 'Damascus');
        // 4 jobs so each application status maps to a distinct job (the
        // (user_id, job_post_id) guard would otherwise collapse duplicates).
        $jobs = $this->jobsFor($company, $employer, 4);
        $this->summary['Employer (approved)'] = ['email' => $employer->email, 'password' => self::PW_EMPLOYER, 'note' => 'Company "Scenario Tech Co" with 3 jobs; receives applications + sends offers'];

        // ── Pending employer (awaiting admin approval) ───────────
        $pendingEmployer = $this->user('scenario.pending-employer@test.demo', 'Scenario Pending Employer', ['employer'], self::PW_EMPLOYER, ['is_employer' => false]);
        $this->pendingEmployerRecord($pendingEmployer);
        $this->summary['Employer (pending approval)'] = ['email' => $pendingEmployer->email, 'password' => self::PW_EMPLOYER, 'note' => 'Applied to be employer; admin can approve/reject at /api/admin/employers'];

        // ── Seeker A: applications in every status ───────────────
        $seekerA = $this->seeker('scenario.seeker.apps@test.demo', 'Seeker Applications');
        $appStatuses = ['pending', 'reviewed', 'accepted', 'rejected'];
        foreach ($appStatuses as $i => $status) {
            $this->application($seekerA, $jobs[$i % count($jobs)], $status);
        }
        $this->notify($seekerA, 'application_status_changed', 'Your application was reviewed by Scenario Tech Co.', false);
        $this->summary['Seeker — applications'] = ['email' => $seekerA->email, 'password' => self::PW_SEEKER, 'note' => 'Has applications in pending/reviewed/accepted/rejected + 1 unread notification'];

        // ── Seeker B: direct offers in every status ──────────────
        $seekerB = $this->seeker('scenario.seeker.offers@test.demo', 'Seeker Offers');
        foreach (['pending', 'accepted', 'declined'] as $i => $status) {
            $this->offer($employer, $seekerB, $jobs[$i % count($jobs)], $status);
        }
        $this->notify($seekerB, 'direct_offer_received', 'You received a job offer from Scenario Tech Co.', false);
        $this->summary['Seeker — offers'] = ['email' => $seekerB->email, 'password' => self::PW_SEEKER, 'note' => 'Has direct offers in pending/accepted/declined; accept an offer to auto-create an application'];

        // ── Seeker C: meetings in every status ───────────────────
        $seekerC = $this->seeker('scenario.seeker.meetings@test.demo', 'Seeker Meetings');
        $meetingStatuses = ['pending', 'accepted', 'declined', 'completed', 'cancelled', 'rescheduled'];
        foreach ($meetingStatuses as $i => $status) {
            $this->meeting($employer, $seekerC, $status, $i);
        }
        $this->notify($seekerC, 'broadcast', 'Welcome to the platform! Complete your profile to get noticed.', true);
        $this->summary['Seeker — meetings'] = ['email' => $seekerC->email, 'password' => self::PW_SEEKER, 'note' => 'Has meetings in pending/accepted/declined/completed/cancelled/rescheduled (with Scenario Employer)'];

        // ── Seeker D: fresh account, nothing yet (clean slate) ───
        $seekerD = $this->seeker('scenario.seeker.fresh@test.demo', 'Seeker Fresh');
        $this->summary['Seeker — fresh'] = ['email' => $seekerD->email, 'password' => self::PW_SEEKER, 'note' => 'Clean profile — use to test applying, uploading a CV, receiving offers'];

        $this->printSummary();
    }

    // ─────────────────────────────────────────────────────────────
    // Builders
    // ─────────────────────────────────────────────────────────────

    private function user(string $email, string $name, array $roles, string $password, array $extra = []): User
    {
        $user = User::where('email', $email)->first();
        if ($user) {
            $user->update(array_merge(['roles' => $roles], $extra));

            return $user;
        }

        return User::create(array_merge([
            'name'              => $name,
            'email'             => $email,
            'password'          => Hash::make($password),
            'roles'             => $roles,
            'email_verified_at' => now(),
            'demo_source'       => self::TAG,
        ], $extra));
    }

    private function seeker(string $email, string $name): User
    {
        $user = $this->user($email, $name, ['employee'], self::PW_SEEKER);

        $data = [
            'user_id'             => (string) $user->_id,
            'first_name'          => explode(' ', $name)[0],
            'last_name'           => explode(' ', $name)[1] ?? '',
            'full_name'           => $name,
            'city'                => 'Damascus',
            'location'            => 'Damascus, Syria',
            'current_job_title'   => 'Business Analyst',
            'job_level'           => 'mid',
            'years_of_experience' => 4,
            'is_actively_seeking' => true,
            'ai_full_name'        => $name,
            'ai_location'         => 'Damascus, Syria',
            'ai_summary'          => 'Scenario test seeker used for manual QA.',
            'ai_skills'           => ['Requirements Gathering', 'SQL', 'JIRA', 'Agile'],
            'ats_score'           => 80,
            'analysis_status'     => JobSeekerProfile::ANALYSIS_COMPLETED,
            'ai_analyzed_at'      => now(),
            'demo_source'         => self::TAG,
        ];

        $profile = JobSeekerProfile::where('user_id', (string) $user->_id)->first();
        $profile ? $profile->update($data) : JobSeekerProfile::create($data);

        return $user;
    }

    private function approveEmployerRecord(User $employer): void
    {
        $rec = Employer::where('user_id', (string) $employer->_id)->first();
        if (! $rec) {
            Employer::create([
                'user_id'      => (string) $employer->_id,
                'status'       => Employer::STATUS_APPROVED,
                'review_notes' => 'Auto-approved (scenario seed).',
                'reviewed_at'  => now(),
                'demo_source'  => self::TAG,
            ]);
        } elseif ($rec->status !== Employer::STATUS_APPROVED) {
            $rec->update(['status' => Employer::STATUS_APPROVED, 'reviewed_at' => now()]);
        }
    }

    private function pendingEmployerRecord(User $employer): void
    {
        $rec = Employer::where('user_id', (string) $employer->_id)->first();
        if (! $rec) {
            Employer::create([
                'user_id'     => (string) $employer->_id,
                'status'      => Employer::STATUS_PENDING,
                'demo_source' => self::TAG,
            ]);
        }
    }

    private function company(User $employer, string $name, string $city): CompanyProfile
    {
        $company = CompanyProfile::where('employer_id', (string) $employer->_id)->first();
        if ($company) {
            return $company;
        }

        return CompanyProfile::create([
            'employer_id'   => (string) $employer->_id,
            'name'          => $name,
            'slug'          => Str::slug($name),
            'logo'          => 'https://placehold.co/200x200?text=Scenario',
            'description'   => 'Scenario company used for manual end-to-end testing.',
            'industry'      => 'Information Technology',
            'company_size'  => '51_to_200',
            'city'          => $city,
            'country'       => 'Syria',
            'email'         => 'careers@scenario-tech.demo',
            'phone_main'    => '+963 11 999 0000',
            'phone_visible' => true,
            'demo_source'   => self::TAG,
        ]);
    }

    /** @return array<int, JobPost> */
    private function jobsFor(CompanyProfile $company, User $employer, int $count): array
    {
        $titles = [
            ['Business Analyst', 'Product & Management', ['Business Analyst'], 'mid'],
            ['Senior Java Developer', 'Information Technology', ['Java Developer'], 'senior'],
            ['Project Manager', 'Product & Management', ['Project Manager'], 'senior'],
            ['QA Engineer', 'Information Technology', ['QA Engineer'], 'mid'],
        ];

        $jobs = [];
        for ($i = 0; $i < $count; $i++) {
            [$title, $category, $roles, $level] = $titles[$i % count($titles)];
            $jobId = 'SCN-' . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);

            $job = JobPost::where('job_id', $jobId)->first();
            if (! $job) {
                $job = JobPost::create([
                    'job_id'             => $jobId,
                    'employer_id'        => (string) $employer->_id,
                    'company_profile_id' => (string) $company->_id,
                    'company_name'       => $company->name,
                    'company_logo'       => $company->logo,
                    'communication_method' => JobPost::COMM_BY_FORSA,
                    'title'              => $title,
                    'roles'              => $roles,
                    'category'           => $category,
                    'job_level'          => $level,
                    'experience_years'   => $level === 'senior' ? 6 : 3,
                    'gender'             => 'no_preference',
                    'education_level'    => 'bachelor',
                    'languages'          => ['Arabic', 'English'],
                    'vacancies'          => 2,
                    'job_type'           => 'full_time',
                    'work_mode'          => 'hybrid',
                    'city'               => $company->city,
                    'address'            => $company->city . ', Syria',
                    'salary_from'        => 1200,
                    'salary_to'          => 2600,
                    'currency'           => 'USD',
                    'display_salary'     => true,
                    'description'        => "Scenario job: {$title} at {$company->name}.",
                    'requirements'       => "Experience as a {$title}.",
                    'questions'          => [],
                    'tags'               => ['scenario', strtolower($title)],
                    'is_active'          => true,
                    'expires_at'         => now()->addDays(60),
                    'created_at'         => now()->subDays($i),
                    'demo_source'        => self::TAG,
                ]);
            }
            $jobs[] = $job;
        }

        return $jobs;
    }

    private function application(User $seeker, JobPost $job, string $status): void
    {
        $exists = Application::where('user_id', (string) $seeker->_id)
            ->where('job_post_id', (string) $job->_id)
            ->exists();
        if ($exists) {
            return;
        }

        Application::create([
            'user_id'      => (string) $seeker->_id,
            'job_post_id'  => (string) $job->_id,
            'cover_letter' => "Application for {$job->title} in state: {$status}.",
            'status'       => $status,
            'feedback'     => $status === 'rejected' ? 'Not a fit at this time.' : null,
            'applied_at'   => now()->subDays(rand(1, 10)),
            'answers'      => [],
            'demo_source'  => self::TAG,
        ]);
    }

    private function offer(User $employer, User $seeker, JobPost $job, string $status): void
    {
        $exists = DirectOffer::where('employer_id', (string) $employer->_id)
            ->where('job_seeker_id', (string) $seeker->_id)
            ->where('job_post_id', (string) $job->_id)
            ->exists();
        if ($exists) {
            return;
        }

        DirectOffer::create([
            'employer_id'   => (string) $employer->_id,
            'job_seeker_id' => (string) $seeker->_id,
            'job_post_id'   => (string) $job->_id,
            'message'       => "Offer for {$job->title} in state: {$status}.",
            'status'        => $status,
            'demo_source'   => self::TAG,
        ]);

        if ($status === 'accepted') {
            $this->application($seeker, $job, 'pending');
        }
    }

    private function meeting(User $organizer, User $invitee, string $status, int $offset): void
    {
        $exists = Meeting::where('organizer_id', (string) $organizer->_id)
            ->where('invitee_id', (string) $invitee->_id)
            ->where('status', $status)
            ->exists();
        if ($exists) {
            return;
        }

        $type = ['video_call', 'phone_call', 'in_person'][$offset % 3];
        // Future date for active states, past for completed.
        $date = $status === 'completed'
            ? now()->subDays(3)->format('Y-m-d')
            : now()->addDays($offset + 2)->format('Y-m-d');

        Meeting::create([
            'organizer_id'              => (string) $organizer->_id,
            'invitee_id'                => (string) $invitee->_id,
            'title'                     => ucfirst($status) . ' Interview',
            'meeting_type'              => $type,
            'proposed_date'             => $date,
            'proposed_start_time'       => sprintf('%02d:00', 9 + $offset),
            'proposed_duration_minutes' => 45,
            'status'                    => $status,
            'meet_link'                 => $type === 'video_call' ? 'https://meet.google.com/scn-' . Str::random(8) : null,
            'location_or_link'          => $type === 'in_person' ? 'Scenario Tech Co, Damascus' : null,
            'decline_reason'            => $status === 'declined' ? 'Not available at the proposed time.' : null,
            'cancellation_reason'       => $status === 'cancelled' ? 'Position filled.' : null,
            'cancelled_by'              => $status === 'cancelled' ? (string) $organizer->_id : null,
            'notes'                     => $status === 'completed' ? [['author_id' => (string) $organizer->_id, 'note' => 'Strong candidate, moving forward.', 'created_at' => now()->toIso8601String()]] : [],
            'demo_source'               => self::TAG,
        ]);
    }

    private function notify(User $user, string $type, string $message, bool $read): void
    {
        $exists = Notification::where('user_id', (string) $user->_id)
            ->where('type', $type)
            ->where('message', $message)
            ->exists();
        if ($exists) {
            return;
        }

        Notification::create([
            'user_id'     => (string) $user->_id,
            'type'        => $type,
            'message'     => $message,
            'read_at'     => $read ? now() : null,
            'demo_source' => self::TAG,
        ]);
    }

    private function printSummary(): void
    {
        $this->command->newLine();
        $this->command->info('=== SCENARIO TEST ACCOUNTS ===');
        foreach ($this->summary as $role => $info) {
            $this->command->line(sprintf('  %-30s %s / %s', $role, $info['email'], $info['password']));
            $this->command->line(sprintf('  %-30s   %s', '', $info['note']));
        }
    }
}
