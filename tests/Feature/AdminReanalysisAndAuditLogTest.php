<?php

// Admin audit-log viewer (GET /api/admin/audit-log) and manual CV
// re-analysis (POST /api/admin/users/{userId}/reanalyze).

use App\Models\AuditLog;
use App\Models\JobSeekerProfile;
use App\Services\AuditLogService;
use App\Services\CvAnalysisService;

/** A CvAnalysisService stub that returns fixed analysis data without any network call. */
function fakeReanalysisService(array $analysis = ['ats_score' => 75, 'skills' => ['PHP'], 'full_name' => null, 'summary' => null]): void
{
    test()->instance(CvAnalysisService::class, new class($analysis) extends CvAnalysisService
    {
        public function __construct(private array $analysis) {}

        public function analyze(string $fileUrl, string $resumeId, ?string $mimeType = null): array
        {
            return $this->analysis;
        }
    });
}

beforeEach(function () {
    [$this->admin, $this->adminToken] = userWithToken('admin');
});

// ── GET /api/admin/audit-log ─────────────────────────────────────────

test('unauthenticated user cannot view the audit log', function () {
    $this->getJson('/api/admin/audit-log')->assertUnauthorized();
});

test('non-admin cannot view the audit log', function () {
    $this->withToken(tokenFor('employee'))
        ->getJson('/api/admin/audit-log')
        ->assertForbidden();
});

test('audit log returns paginated entries', function () {
    AuditLogService::log('broadcast_sent', (string) $this->admin->_id, $this->admin->name);
    AuditLogService::log('employer_approved', (string) $this->admin->_id, $this->admin->name);

    $resp = $this->withToken($this->adminToken)->getJson('/api/admin/audit-log')
        ->assertOk()
        ->assertJsonStructure(['data', 'current_page', 'per_page', 'total']);

    expect(count($resp->json('data')))->toBeGreaterThanOrEqual(2);
});

test('audit log action_type filter returns only matching entries', function () {
    AuditLogService::log('broadcast_sent', (string) $this->admin->_id, $this->admin->name);
    AuditLogService::log('employer_approved', (string) $this->admin->_id, $this->admin->name);
    AuditLogService::log('employer_approved', (string) $this->admin->_id, $this->admin->name);

    $resp = $this->withToken($this->adminToken)
        ->getJson('/api/admin/audit-log?action_type=employer_approved')
        ->assertOk();

    $actions = collect($resp->json('data'))->pluck('action')->unique()->toArray();
    expect($actions)->toBe(['employer_approved'])
        ->and(count($resp->json('data')))->toBe(2);
});

test('audit log date_from filter excludes earlier entries', function () {
    AuditLogService::log('broadcast_sent', (string) $this->admin->_id, $this->admin->name);

    $tomorrow = now()->addDay()->format('Y-m-d');

    $this->withToken($this->adminToken)
        ->getJson('/api/admin/audit-log?date_from='.$tomorrow)
        ->assertOk()
        ->assertJsonPath('total', 0);
});

test('audit log is read-only with no delete or put routes', function () {
    $deleteStatus = $this->withToken($this->adminToken)->deleteJson('/api/admin/audit-log/some-id')->status();
    $putStatus    = $this->withToken($this->adminToken)->putJson('/api/admin/audit-log/some-id')->status();

    expect($deleteStatus)->toBeIn([404, 405])
        ->and($putStatus)->toBeIn([404, 405]);
});

// ── POST /api/admin/users/{userId}/reanalyze ─────────────────────────

test('unauthenticated user cannot trigger reanalysis', function () {
    $this->postJson('/api/admin/users/fakeid/reanalyze')->assertUnauthorized();
});

test('reanalyze returns 404 for a non-employee user', function () {
    $employer = createUser('employer');

    $this->withToken($this->adminToken)
        ->postJson("/api/admin/users/{$employer->_id}/reanalyze")
        ->assertNotFound()
        ->assertJson(['message' => 'User not found']);
});

test('reanalyze returns 404 for a non-existent user', function () {
    $this->withToken($this->adminToken)
        ->postJson('/api/admin/users/000000000000000000000000/reanalyze')
        ->assertNotFound();
});

test('reanalyze returns 422 when the user has no cv', function () {
    [$seeker] = createSeekerWithProfile();

    $this->withToken($this->adminToken)
        ->postJson("/api/admin/users/{$seeker->_id}/reanalyze")
        ->assertStatus(422)
        ->assertJson(['message' => 'No CV file found for this user']);
});

test('reanalyze sets analysis_status after a successful run', function () {
    [$seeker, $profile] = createSeekerWithProfile([], ['cv_file_path' => 'https://example.com/cv.pdf']);
    fakeReanalysisService();

    $this->withToken($this->adminToken)
        ->postJson("/api/admin/users/{$seeker->_id}/reanalyze")
        ->assertOk();

    expect($profile->fresh()->analysis_status)->toBeIn(['processing', 'completed', 'error']);
});

test('reanalyze writes an audit log entry', function () {
    [$seeker] = createSeekerWithProfile([], ['cv_file_path' => 'https://example.com/cv.pdf']);
    fakeReanalysisService(['ats_score' => 80, 'skills' => []]);

    $this->withToken($this->adminToken)
        ->postJson("/api/admin/users/{$seeker->_id}/reanalyze")
        ->assertOk();

    $log = AuditLog::where('action', 'cv_reanalysis_triggered')
        ->where('target_id', (string) $seeker->_id)
        ->first();
    expect($log)->not->toBeNull();
});
