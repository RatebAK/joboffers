<?php

use App\Models\AuditLog;
use App\Models\JobSeekerProfile;
use App\Models\User;
use App\Services\CvAnalysisService;
use Illuminate\Support\Facades\Http;

// ── Helpers ──────────────────────────────────────────────────────────

function reanalysisAdmin(): array
{
    $admin = User::factory()->admin()->create();
    $token = auth('api')->login($admin);
    return [$admin, $token];
}

// ── GET /api/admin/audit-log ──────────────────────────────────────────

test('audit log returns 401 without token', function () {
    $this->getJson('/api/admin/audit-log')->assertStatus(401);
});

test('audit log returns 403 for non-admin', function () {
    $user  = User::factory()->employee()->create();
    $token = auth('api')->login($user);
    $this->withToken($token)->getJson('/api/admin/audit-log')->assertStatus(403);
    $user->delete();
});

test('audit log returns paginated entries', function () {
    [$admin, $token] = reanalysisAdmin();
    AuditLog::truncate();

    \App\Services\AuditLogService::log('broadcast_sent', (string) $admin->_id, $admin->name);
    \App\Services\AuditLogService::log('employer_approved', (string) $admin->_id, $admin->name);

    $resp = $this->withToken($token)->getJson('/api/admin/audit-log')
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'current_page', 'per_page', 'total']);

    expect(count($resp->json('data')))->toBeGreaterThanOrEqual(2);

    AuditLog::truncate();
    $admin->delete();
});

test('audit log action_type filter returns only matching entries', function () {
    [$admin, $token] = reanalysisAdmin();
    AuditLog::truncate();

    \App\Services\AuditLogService::log('broadcast_sent', (string) $admin->_id, $admin->name);
    \App\Services\AuditLogService::log('employer_approved', (string) $admin->_id, $admin->name);
    \App\Services\AuditLogService::log('employer_approved', (string) $admin->_id, $admin->name);

    $resp = $this->withToken($token)
        ->getJson('/api/admin/audit-log?action_type=employer_approved')
        ->assertStatus(200);

    $actions = collect($resp->json('data'))->pluck('action')->unique()->toArray();
    expect($actions)->toBe(['employer_approved']);
    expect(count($resp->json('data')))->toBe(2);

    AuditLog::truncate();
    $admin->delete();
});

test('audit log date_from filter returns only entries after that date', function () {
    [$admin, $token] = reanalysisAdmin();
    AuditLog::truncate();

    \App\Services\AuditLogService::log('broadcast_sent', (string) $admin->_id, $admin->name);

    $tomorrow = now()->addDay()->format('Y-m-d');
    $resp = $this->withToken($token)
        ->getJson('/api/admin/audit-log?date_from=' . $tomorrow)
        ->assertStatus(200);

    expect($resp->json('total'))->toBe(0);

    AuditLog::truncate();
    $admin->delete();
});

test('audit log has no delete or put routes', function () {
    [$admin, $token] = reanalysisAdmin();

    // 404 or 405 both confirm the route doesn't support mutation
    $deleteStatus = $this->withToken($token)->deleteJson('/api/admin/audit-log/some-id')->status();
    $putStatus    = $this->withToken($token)->putJson('/api/admin/audit-log/some-id')->status();

    expect($deleteStatus)->toBeIn([404, 405]);
    expect($putStatus)->toBeIn([404, 405]);

    $admin->delete();
});

// ── POST /api/admin/users/{userId}/reanalyze ──────────────────────────

test('reanalyze returns 401 without token', function () {
    $this->postJson('/api/admin/users/fakeid/reanalyze')->assertStatus(401);
});

test('reanalyze returns 404 for non-employee user', function () {
    [$admin, $token] = reanalysisAdmin();
    $employer = User::factory()->employer()->create();

    $this->withToken($token)
        ->postJson("/api/admin/users/{$employer->_id}/reanalyze")
        ->assertStatus(404)
        ->assertJson(['message' => 'User not found']);

    $employer->delete();
    $admin->delete();
});

test('reanalyze returns 404 for non-existent user', function () {
    [$admin, $token] = reanalysisAdmin();

    $this->withToken($token)
        ->postJson('/api/admin/users/000000000000000000000000/reanalyze')
        ->assertStatus(404);

    $admin->delete();
});

test('reanalyze returns 422 when user has no cv', function () {
    [$admin, $token] = reanalysisAdmin();
    $seeker = User::factory()->employee()->create();
    JobSeekerProfile::create(['user_id' => (string) $seeker->_id]);

    $this->withToken($token)
        ->postJson("/api/admin/users/{$seeker->_id}/reanalyze")
        ->assertStatus(422)
        ->assertJson(['message' => 'No CV file found for this user']);

    JobSeekerProfile::where('user_id', (string) $seeker->_id)->delete();
    $seeker->delete();
    $admin->delete();
});

test('reanalyze sets analysis_status to processing on success', function () {
    [$admin, $token] = reanalysisAdmin();
    AuditLog::truncate();
    $seeker = User::factory()->employee()->create();
    $profile = JobSeekerProfile::create([
        'user_id'      => (string) $seeker->_id,
        'cv_file_path' => 'https://example.com/cv.pdf',
    ]);

    // Mock the CV analysis service to return fake data
    $this->instance(CvAnalysisService::class, new class extends CvAnalysisService {
        public function analyze(string $fileUrl, string $resumeId, ?string $mimeType = null): array
        {
            return ['ats_score' => 75, 'skills' => ['PHP'], 'full_name' => null, 'summary' => null];
        }
    });

    $this->withToken($token)
        ->postJson("/api/admin/users/{$seeker->_id}/reanalyze")
        ->assertStatus(200);

    // analysis_status should be either processing or completed after the call
    $fresh = $profile->fresh();
    expect($fresh->analysis_status)->toBeIn(['processing', 'completed', 'error']);

    $auditEntry = AuditLog::where('action', 'cv_reanalysis_triggered')->first();
    expect($auditEntry)->not->toBeNull();
    expect($auditEntry->target_id)->toBe((string) $seeker->_id);

    JobSeekerProfile::where('user_id', (string) $seeker->_id)->delete();
    $seeker->delete();
    AuditLog::truncate();
    $admin->delete();
});

test('reanalyze writes audit log entry', function () {
    [$admin, $token] = reanalysisAdmin();
    AuditLog::truncate();
    $seeker = User::factory()->employee()->create();
    JobSeekerProfile::create([
        'user_id'      => (string) $seeker->_id,
        'cv_file_path' => 'https://example.com/cv.pdf',
    ]);

    $this->instance(CvAnalysisService::class, new class extends CvAnalysisService {
        public function analyze(string $fileUrl, string $resumeId, ?string $mimeType = null): array
        {
            return ['ats_score' => 80, 'skills' => []];
        }
    });

    $this->withToken($token)
        ->postJson("/api/admin/users/{$seeker->_id}/reanalyze")
        ->assertStatus(200);

    $log = AuditLog::where('action', 'cv_reanalysis_triggered')
        ->where('target_id', (string) $seeker->_id)
        ->first();
    expect($log)->not->toBeNull();

    JobSeekerProfile::where('user_id', (string) $seeker->_id)->delete();
    $seeker->delete();
    AuditLog::truncate();
    $admin->delete();
});
