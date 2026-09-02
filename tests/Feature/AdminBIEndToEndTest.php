<?php

// Admin business-intelligence end-to-end scenarios exercising several features
// together: employer lifecycle + audit trail, churn + broadcast, bulk
// onboarding, CV re-analysis + notifications, and the talent anonymity gate.
// Queues and mail are faked — nothing leaves the process.

use App\Jobs\SendBroadcastJob;
use App\Jobs\SendBulkInviteJob;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Employer;
use App\Models\JobPost;
use App\Models\JobSeekerProfile;
use App\Models\Notification;
use App\Services\CvAnalysisService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/** Build an in-memory employer-onboarding CSV UploadedFile (not covered by shared helpers). */
function e2eCsvFile(array $rows): UploadedFile
{
    $lines = ['name,email,company_name,partner_type'];
    foreach ($rows as $r) {
        $lines[] = implode(',', $r);
    }
    $path = tempnam(sys_get_temp_dir(), 'e2e_').'.csv';
    file_put_contents($path, implode("\n", $lines));

    return new UploadedFile($path, 'employers.csv', 'text/csv', null, true);
}

beforeEach(function () {
    Queue::fake();
    Mail::fake();
    [$this->admin, $this->adminToken] = userWithToken('admin');
});

// ── Scenario A: employer lifecycle + audit trail ─────────────────────

test('Scenario A: approving a pending employer creates an audit log and notification and clears the pipeline', function () {
    $empUser  = createUser('employee');
    $employer = Employer::create([
        'user_id'       => (string) $empUser->_id,
        'status'        => 'pending',
        'document_path' => 'docs/test.pdf',
        'document_name' => 'test.pdf',
        'created_at'    => now()->subDays(2),
        'updated_at'    => now()->subDays(2),
    ]);

    // Employer appears in the pipeline report.
    $pipeline = $this->withToken($this->adminToken)->getJson('/api/admin/reports/pipeline')->assertOk();
    expect($pipeline->json('pending_count'))->toBeGreaterThanOrEqual(1)
        ->and(collect($pipeline->json('employers'))->pluck('user_id'))->toContain((string) $empUser->_id);

    // Approve the employer.
    $this->withToken($this->adminToken)
        ->postJson("/api/admin/employers/{$employer->_id}/approve")
        ->assertOk();

    // Audit log entry recorded.
    expect(AuditLog::where('action', 'employer_approved')->where('target_id', (string) $employer->_id)->first())
        ->not->toBeNull();

    // Notification created for the employer's user.
    expect(Notification::where('user_id', (string) $empUser->_id)->where('type', 'employer_decision')->first())
        ->not->toBeNull();

    // Approved employer no longer appears in the pipeline.
    $freshPipeline = $this->withToken($this->adminToken)->getJson('/api/admin/reports/pipeline')->assertOk();
    expect(collect($freshPipeline->json('employers'))->pluck('user_id')->toArray())
        ->not->toContain((string) $empUser->_id);
});

// ── Scenario B: churn detection + re-engagement broadcast ────────────

test('Scenario B: churned users appear in the report and a broadcast queues jobs and logs an audit entry', function () {
    $churnedEmployer = createUser('employer');
    [$churnedSeeker] = createSeekerWithProfile([], ['cv_file_path' => 'https://example.com/cv.pdf']);

    // Both appear in the churn report.
    $churn = $this->withToken($this->adminToken)->getJson('/api/admin/reports/churn?window_days=30')->assertOk();
    expect(collect($churn->json('employers'))->pluck('user_id')->toArray())->toContain((string) $churnedEmployer->_id)
        ->and(collect($churn->json('seekers'))->pluck('user_id')->toArray())->toContain((string) $churnedSeeker->_id);

    // Fire a broadcast to everyone.
    $broadcast = $this->withToken($this->adminToken)->postJson('/api/admin/broadcast', [
        'subject'  => 'We miss you!',
        'body'     => 'Come back to the platform.',
        'audience' => 'all',
    ])->assertOk();

    expect($broadcast->json('status'))->toBe('queued')
        ->and($broadcast->json('recipient_count'))->toBeGreaterThanOrEqual(1);
    Queue::assertPushed(SendBroadcastJob::class);

    // Broadcast audit log entry recorded and queryable by action_type.
    expect(AuditLog::where('action', 'broadcast_sent')->first())->not->toBeNull();

    $auditResp = $this->withToken($this->adminToken)
        ->getJson('/api/admin/audit-log?action_type=broadcast_sent')
        ->assertOk();
    expect($auditResp->json('total'))->toBeGreaterThanOrEqual(1);
});

// ── Scenario C: bulk B2B onboarding ──────────────────────────────────

test('Scenario C: bulk onboarding creates accounts, queues invites, logs an audit entry, and updates the pipeline', function () {
    $existingEmail = 'existing_e2e_'.uniqid().'@corp.com';
    createUser('employee', ['email' => $existingEmail]);

    $rows = [
        ['Corp A', 'e2e_corpa_'.uniqid().'@test.com', 'Corp A Ltd', 'agency'],
        ['Corp B', 'e2e_corpb_'.uniqid().'@test.com', 'Corp B Ltd', 'university'],
        ['Corp C', 'e2e_corpc_'.uniqid().'@test.com', 'Corp C Ltd', ''],
        ['Dup',    $existingEmail,                     'Dup Corp',   ''], // duplicate
    ];

    $resp = $this->withToken($this->adminToken)
        ->postJson('/api/admin/onboarding/bulk', ['file' => e2eCsvFile($rows)])
        ->assertOk();

    expect($resp->json('created'))->toBe(3)
        ->and($resp->json('skipped'))->toBe(1)
        ->and($resp->json('created') + $resp->json('skipped'))->toBe($resp->json('total_rows'))->toBe(4);

    Queue::assertPushed(SendBulkInviteJob::class, 3);

    $log = AuditLog::where('action', 'bulk_employer_onboarded')->first();
    expect($log)->not->toBeNull()
        ->and($log->metadata['created_count'])->toBe(3);

    // Pipeline reflects the 3 new pending employers.
    $pipeline = $this->withToken($this->adminToken)->getJson('/api/admin/reports/pipeline')->assertOk();
    expect($pipeline->json('pending_count'))->toBeGreaterThanOrEqual(3);
});

// ── Scenario D: CV re-analysis + notification chain ──────────────────

test('Scenario D: re-analysis logs an audit entry, an application status change notifies the seeker, and mark-all-read works', function () {
    [$seeker, $seekerToken] = userWithToken('employee');
    $profile = JobSeekerProfile::create([
        'user_id'      => (string) $seeker->_id,
        'cv_file_path' => 'https://example.com/cv.pdf',
    ]);

    $this->instance(CvAnalysisService::class, new class extends CvAnalysisService
    {
        public function analyze(string $fileUrl, string $resumeId, ?string $mimeType = null): array
        {
            return ['ats_score' => 80, 'skills' => ['PHP'], 'full_name' => null, 'summary' => null];
        }
    });

    // Trigger re-analysis and confirm the audit entry.
    $this->withToken($this->adminToken)
        ->postJson("/api/admin/users/{$seeker->_id}/reanalyze")
        ->assertOk();

    expect(AuditLog::where('action', 'cv_reanalysis_triggered')->where('target_id', (string) $seeker->_id)->first())
        ->not->toBeNull();

    // An application status change notifies the seeker.
    $employer = createUser('employer');
    $jobPost  = createJob($employer, ['title' => 'E2E Job']);
    $application = Application::create([
        'user_id'     => (string) $seeker->_id,
        'job_post_id' => (string) $jobPost->_id,
        'status'      => 'pending',
    ]);

    $application->update(['status' => 'reviewed']);

    expect(Notification::where('user_id', (string) $seeker->_id)->where('type', 'application_status_changed')->first())
        ->not->toBeNull();

    // Unread count is at least 1, then zero after mark-all-read.
    $this->withToken($seekerToken)->getJson('/api/notifications/unread-count')
        ->assertOk();
    expect($this->withToken($seekerToken)->getJson('/api/notifications/unread-count')->json('unread_count'))
        ->toBeGreaterThanOrEqual(1);

    $this->withToken($seekerToken)->postJson('/api/notifications/read-all')->assertOk();

    $this->withToken($seekerToken)->getJson('/api/notifications/unread-count')
        ->assertOk()
        ->assertJson(['unread_count' => 0]);
});

// ── Scenario E: talent report anonymity gate ─────────────────────────

test('Scenario E: talent report is gated below 5 profiles and returns no PII at 5+', function () {
    $piiFields = ['name', 'email', 'phone', 'user_id', 'ai_email', 'ai_phone', 'ai_full_name', 'ai_location'];

    // 4 profiles → 422.
    foreach (range(1, 4) as $i) {
        createSeekerWithProfile([], [
            'ai_skills'    => ["Skill_{$i}"],
            'ats_score'    => 50 + $i,
            'cv_file_path' => 'https://example.com/cv.pdf',
            'ai_full_name' => 'Private Name',
            'ai_email'     => 'private@example.com',
        ]);
    }

    $this->withToken($this->adminToken)->getJson('/api/admin/reports/talent')
        ->assertStatus(422)
        ->assertJson(['message' => 'Insufficient data for anonymized report']);

    // 5th profile → 200 with no PII.
    createSeekerWithProfile([], [
        'ai_skills'    => ['PHP'],
        'ats_score'    => 60,
        'cv_file_path' => 'https://example.com/cv5.pdf',
    ]);

    $resp = $this->withToken($this->adminToken)->getJson('/api/admin/reports/talent')
        ->assertOk()
        ->assertJsonStructure(['profile_count', 'top_skills', 'ats_stats']);

    foreach ($piiFields as $pii) {
        expect(array_keys($resp->json()))->not->toContain($pii);
    }
});
