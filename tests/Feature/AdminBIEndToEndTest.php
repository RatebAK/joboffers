<?php

/**
 * Admin Business Intelligence — End-to-End Integration Tests
 *
 * Covers five full-flow scenarios that exercise multiple features together.
 * Uses Queue::fake() and Mail::fake() — no real queues or mail.
 */

use App\Jobs\SendBroadcastJob;
use App\Jobs\SendBulkInviteJob;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Employer;
use App\Models\JobPost;
use App\Models\JobSeekerProfile;
use App\Models\Notification;
use App\Models\User;
use App\Services\CvAnalysisService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

// ── Global setup helpers ──────────────────────────────────────────────

function e2eAdmin(): array
{
    $admin = User::factory()->admin()->create();
    $token = auth('api')->login($admin);
    return [$admin, $token];
}

function e2eCsvFile(array $rows): UploadedFile
{
    $header  = "name,email,company_name,partner_type";
    $lines   = [$header];
    foreach ($rows as $r) {
        $lines[] = implode(',', $r);
    }
    $content = implode("\n", $lines);
    $path    = tempnam(sys_get_temp_dir(), 'e2e_') . '.csv';
    file_put_contents($path, $content);
    return new UploadedFile($path, 'employers.csv', 'text/csv', null, true);
}

// ─────────────────────────────────────────────────────────────────────
// Scenario A: Employer lifecycle + audit trail
// ─────────────────────────────────────────────────────────────────────

test('Scenario A: pending employer appears in pipeline, approval creates audit log and notification', function () {
    Queue::fake();
    Mail::fake();
    AuditLog::truncate();
    Notification::truncate();

    [$admin, $token] = e2eAdmin();

    // Create a pending employer
    $empUser  = User::factory()->create(['roles' => ['employee']]);
    $employer = Employer::create([
        'user_id'       => (string) $empUser->_id,
        'status'        => 'pending',
        'document_path' => 'docs/test.pdf',
        'document_name' => 'test.pdf',
        'created_at'    => now()->subDays(2),
        'updated_at'    => now()->subDays(2),
    ]);

    // Assert employer appears in pipeline report
    $pipeline = $this->withToken($token)
        ->getJson('/api/admin/reports/pipeline')
        ->assertStatus(200);

    expect($pipeline->json('pending_count'))->toBeGreaterThanOrEqual(1);
    $listed = collect($pipeline->json('employers'))->pluck('user_id');
    expect($listed)->toContain((string) $empUser->_id);

    // Approve the employer
    $this->withToken($token)
        ->postJson("/api/admin/employers/{$employer->_id}/approve")
        ->assertStatus(200);

    // Assert audit log entry
    $auditLog = AuditLog::where('action', 'employer_approved')
        ->where('target_id', (string) $employer->_id)
        ->first();
    expect($auditLog)->not->toBeNull();

    // Assert notification created for employer's user
    $notif = Notification::where('user_id', (string) $empUser->_id)
        ->where('type', 'employer_decision')
        ->first();
    expect($notif)->not->toBeNull();

    // Assert approved employer no longer in pipeline
    $freshPipeline = $this->withToken($token)
        ->getJson('/api/admin/reports/pipeline')
        ->assertStatus(200);

    $listedIds = collect($freshPipeline->json('employers'))->pluck('user_id')->toArray();
    expect($listedIds)->not->toContain((string) $empUser->_id);

    // Cleanup
    $employer->delete();
    $empUser->delete();
    AuditLog::truncate();
    Notification::truncate();
    $admin->delete();
});

// ─────────────────────────────────────────────────────────────────────
// Scenario B: Churn detection + re-engagement broadcast
// ─────────────────────────────────────────────────────────────────────

test('Scenario B: churned users appear in report, broadcast queues jobs and creates audit log', function () {
    Queue::fake();
    Mail::fake();
    AuditLog::truncate();

    [$admin, $token] = e2eAdmin();

    // Seed churned employer (no posts)
    $churnedEmployer = User::factory()->employer()->create();

    // Seed churned seeker (cv but no applications)
    $churnedSeeker = User::factory()->employee()->create();
    $profile = JobSeekerProfile::create([
        'user_id'      => (string) $churnedSeeker->_id,
        'cv_file_path' => 'https://example.com/cv.pdf',
    ]);

    // Assert both appear in churn report
    $churn = $this->withToken($token)
        ->getJson('/api/admin/reports/churn?window_days=30')
        ->assertStatus(200);

    $employerIds = collect($churn->json('employers'))->pluck('user_id')->toArray();
    $seekerIds   = collect($churn->json('seekers'))->pluck('user_id')->toArray();
    expect($employerIds)->toContain((string) $churnedEmployer->_id);
    expect($seekerIds)->toContain((string) $churnedSeeker->_id);

    // Fire broadcast to all
    $broadcast = $this->withToken($token)->postJson('/api/admin/broadcast', [
        'subject'  => 'We miss you!',
        'body'     => 'Come back to the platform.',
        'audience' => 'all',
    ])->assertStatus(200);

    expect($broadcast->json('status'))->toBe('queued');
    expect($broadcast->json('recipient_count'))->toBeGreaterThanOrEqual(1);
    Queue::assertPushed(SendBroadcastJob::class);

    // Assert broadcast audit log
    $broadcastLog = AuditLog::where('action', 'broadcast_sent')->first();
    expect($broadcastLog)->not->toBeNull();

    // Assert audit log is queryable by action_type
    $auditResp = $this->withToken($token)
        ->getJson('/api/admin/audit-log?action_type=broadcast_sent')
        ->assertStatus(200);
    expect($auditResp->json('total'))->toBeGreaterThanOrEqual(1);

    // Cleanup
    $profile->delete();
    $churnedEmployer->delete();
    $churnedSeeker->delete();
    AuditLog::truncate();
    $admin->delete();
});

// ─────────────────────────────────────────────────────────────────────
// Scenario C: Bulk B2B onboarding
// ─────────────────────────────────────────────────────────────────────

test('Scenario C: bulk onboarding creates accounts, queues invite jobs, writes audit log, updates pipeline', function () {
    Queue::fake();
    Mail::fake();
    AuditLog::truncate();

    [$admin, $token] = e2eAdmin();

    $existingEmail = 'existing_e2e_' . uniqid() . '@corp.com';
    $existingUser  = User::factory()->employee()->create(['email' => $existingEmail]);

    $rows = [
        ['Corp A', 'e2e_corpa_' . uniqid() . '@test.com', 'Corp A Ltd', 'agency'],
        ['Corp B', 'e2e_corpb_' . uniqid() . '@test.com', 'Corp B Ltd', 'university'],
        ['Corp C', 'e2e_corpc_' . uniqid() . '@test.com', 'Corp C Ltd', ''],
        ['Dup',    $existingEmail,                          'Dup Corp',   ''],  // duplicate
    ];
    $file = e2eCsvFile($rows);

    $resp = $this->withToken($token)
        ->postJson('/api/admin/onboarding/bulk', ['file' => $file])
        ->assertStatus(200);

    expect($resp->json('created'))->toBe(3);
    expect($resp->json('skipped'))->toBe(1);
    expect($resp->json('created') + $resp->json('skipped'))->toBe($resp->json('total_rows'))->toBe(4);

    // SendBulkInviteJob dispatched exactly 3 times
    Queue::assertPushed(SendBulkInviteJob::class, 3);

    // Audit log entry created
    $log = AuditLog::where('action', 'bulk_employer_onboarded')->first();
    expect($log)->not->toBeNull();
    expect($log->metadata['created_count'])->toBe(3);

    // Pipeline should reflect 3 new pending employers
    $pipeline = $this->withToken($token)->getJson('/api/admin/reports/pipeline')->assertStatus(200);
    expect($pipeline->json('pending_count'))->toBeGreaterThanOrEqual(3);

    // Cleanup
    foreach ($rows as $row) {
        User::where('email', $row[1])->each(fn ($u) => $u->delete());
        Employer::all()->each(fn ($e) => $e->delete());
    }
    $existingUser->delete();
    AuditLog::truncate();
    $admin->delete();
});

// ─────────────────────────────────────────────────────────────────────
// Scenario D: CV re-analysis + notification chain
// ─────────────────────────────────────────────────────────────────────

test('Scenario D: re-analysis triggers audit log, application update creates notification, mark-all-read works', function () {
    Queue::fake();
    Mail::fake();
    AuditLog::truncate();
    Notification::truncate();

    [$admin, $token] = e2eAdmin();

    $seeker = User::factory()->employee()->create();
    $seekerToken = auth('api')->login($seeker);

    $profile = JobSeekerProfile::create([
        'user_id'      => (string) $seeker->_id,
        'cv_file_path' => 'https://example.com/cv.pdf',
    ]);

    // Mock CV analysis service
    $this->instance(CvAnalysisService::class, new class extends CvAnalysisService {
        public function analyze(string $fileUrl, string $resumeId, ?string $mimeType = null): array
        {
            return ['ats_score' => 80, 'skills' => ['PHP'], 'full_name' => null, 'summary' => null];
        }
    });

    // Step 1: Trigger re-analysis
    $this->withToken($token)
        ->postJson("/api/admin/users/{$seeker->_id}/reanalyze")
        ->assertStatus(200);

    // Step 2: Assert audit log
    $auditLog = AuditLog::where('action', 'cv_reanalysis_triggered')
        ->where('target_id', (string) $seeker->_id)
        ->first();
    expect($auditLog)->not->toBeNull();

    // Step 3: Simulate an application and status change to trigger notification
    $employer = User::factory()->employer()->create();
    $jobPost  = JobPost::create([
        'employer_id' => (string) $employer->_id,
        'title'       => 'E2E Job',
        'is_active'   => true,
    ]);
    $application = Application::create([
        'user_id'     => (string) $seeker->_id,
        'job_post_id' => (string) $jobPost->_id,
        'status'      => 'pending',
    ]);

    // Truncate notifications so we start clean for this sub-scenario
    Notification::truncate();

    $application->update(['status' => 'reviewed']);

    // Step 4: Assert notification was created for seeker
    $notif = Notification::where('user_id', (string) $seeker->_id)
        ->where('type', 'application_status_changed')
        ->first();
    expect($notif)->not->toBeNull();

    // Step 5: Assert unread count >= 1
    $unreadResp = $this->withToken($seekerToken)
        ->getJson('/api/notifications/unread-count')
        ->assertStatus(200);
    expect($unreadResp->json('unread_count'))->toBeGreaterThanOrEqual(1);

    // Step 6: Mark all read
    $this->withToken($seekerToken)
        ->postJson('/api/notifications/read-all')
        ->assertStatus(200);

    // Step 7: Assert unread count is now 0
    $this->withToken($seekerToken)
        ->getJson('/api/notifications/unread-count')
        ->assertStatus(200)
        ->assertJson(['unread_count' => 0]);

    // Cleanup
    $profile->delete();
    $application->delete();
    $jobPost->delete();
    $employer->delete();
    $seeker->delete();
    Notification::truncate();
    AuditLog::truncate();
    $admin->delete();
});

// ─────────────────────────────────────────────────────────────────────
// Scenario E: Talent report anonymity gate
// ─────────────────────────────────────────────────────────────────────

test('Scenario E: talent report returns 422 with fewer than 5 profiles, 200 with no PII when 5+ exist', function () {
    Mail::fake();
    JobSeekerProfile::truncate();

    [$admin, $token] = e2eAdmin();

    $piiFields = ['name', 'email', 'phone', 'user_id', 'ai_email', 'ai_phone', 'ai_full_name', 'ai_location'];

    // Step 1: Seed 4 profiles — should get 422
    $users = collect();
    foreach (range(1, 4) as $i) {
        $u = User::factory()->employee()->create();
        $users->push($u);
        JobSeekerProfile::create([
            'user_id'      => (string) $u->_id,
            'ai_skills'    => ["Skill_{$i}"],
            'ats_score'    => 50 + $i,
            'cv_file_path' => 'https://example.com/cv.pdf',
            'ai_full_name' => 'Private Name',
            'ai_email'     => 'private@example.com',
        ]);
    }

    $this->withToken($token)->getJson('/api/admin/reports/talent')
        ->assertStatus(422)
        ->assertJson(['message' => 'Insufficient data for anonymized report']);

    // Step 2: Add 5th profile — should now return 200 with no PII
    $u5 = User::factory()->employee()->create();
    $users->push($u5);
    JobSeekerProfile::create([
        'user_id'      => (string) $u5->_id,
        'ai_skills'    => ['PHP'],
        'ats_score'    => 60,
        'cv_file_path' => 'https://example.com/cv5.pdf',
    ]);

    $resp = $this->withToken($token)->getJson('/api/admin/reports/talent')
        ->assertStatus(200)
        ->assertJsonStructure(['profile_count', 'top_skills', 'ats_stats']);

    // Assert no PII fields in response keys
    $allKeys = array_keys($resp->json());
    foreach ($piiFields as $pii) {
        expect($allKeys)->not->toContain($pii);
    }

    // Cleanup
    JobSeekerProfile::truncate();
    $users->each(fn ($u) => $u->delete());
    $admin->delete();
});
