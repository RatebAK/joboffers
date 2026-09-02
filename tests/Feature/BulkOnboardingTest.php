<?php

// Bulk B2B employer onboarding via CSV (POST /api/admin/onboarding/bulk).
// Property 6: created + skipped == total rows (row accounting invariant).

use App\Jobs\SendBulkInviteJob;
use App\Models\AuditLog;
use App\Models\Employer;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;

/** Build an in-memory CSV UploadedFile from rows (not covered by shared helpers). */
function makeCsvFile(array $rows, string $header = 'name,email,company_name,partner_type'): UploadedFile
{
    $lines = [$header];
    foreach ($rows as $row) {
        $lines[] = implode(',', $row);
    }
    $tmpPath = tempnam(sys_get_temp_dir(), 'bulk_').'.csv';
    file_put_contents($tmpPath, implode("\n", $lines));

    return new UploadedFile($tmpPath, 'employers.csv', 'text/csv', null, true);
}

beforeEach(function () {
    Queue::fake();
    [$this->admin, $this->adminToken] = userWithToken('admin');
});

// ── Validation ───────────────────────────────────────────────────────

test('unauthenticated user cannot bulk onboard', function () {
    $this->postJson('/api/admin/onboarding/bulk')->assertUnauthorized();
});

test('non-admin cannot bulk onboard', function () {
    $file = makeCsvFile([['Acme', 'acme@test.com', 'Acme Corp', '']]);

    $this->withToken(tokenFor('employee'))
        ->postJson('/api/admin/onboarding/bulk', ['file' => $file])
        ->assertForbidden();
});

test('bulk onboarding requires a file', function () {
    $this->withToken($this->adminToken)
        ->postJson('/api/admin/onboarding/bulk')
        ->assertStatus(422);
});

test('bulk onboarding rejects a csv missing required columns', function () {
    $file = makeCsvFile([['Acme', 'acme@test.com']], 'name,email');

    $this->withToken($this->adminToken)
        ->postJson('/api/admin/onboarding/bulk', ['file' => $file])
        ->assertStatus(422)
        ->assertJson(['message' => 'CSV is missing required column: company_name']);
});

// ── Property 6: row accounting invariant ─────────────────────────────

test('created plus skipped equals total rows', function () {
    $rows = [
        ['Alice', 'alice@corp.com', 'Alice Co', 'agency'],
        ['Bob',   'bob@corp.com',   'Bob Ltd',  ''],
        ['Carol', 'carol@corp.com', 'Carol Inc', 'university'],
    ];

    $data = $this->withToken($this->adminToken)
        ->postJson('/api/admin/onboarding/bulk', ['file' => makeCsvFile($rows)])
        ->assertOk()
        ->json();

    expect($data['created'] + $data['skipped'])->toBe($data['total_rows'])->toBe(3);
});

test('duplicate email rows are skipped with email_exists reason', function () {
    $dupEmail = 'dup_'.uniqid().'@corp.com';
    createUser('employee', ['email' => $dupEmail]);

    $rows = [
        ['Alice', 'alice_'.uniqid().'@corp.com', 'Alice Co', ''],
        ['Dup',   $dupEmail,                     'Dup Corp', ''],
    ];

    $data = $this->withToken($this->adminToken)
        ->postJson('/api/admin/onboarding/bulk', ['file' => makeCsvFile($rows)])
        ->assertOk()
        ->json();

    expect($data['created'])->toBe(1)
        ->and($data['skipped'])->toBe(1);

    $skippedEmails  = collect($data['skipped_rows'])->pluck('email')->toArray();
    $skippedReasons = collect($data['skipped_rows'])->pluck('reason')->toArray();
    expect($skippedEmails)->toContain($dupEmail)
        ->and($skippedReasons)->toContain('email_exists');
});

test('duplicate emails within the same upload create only one user', function () {
    $rows = [
        ['Alice',  'same@corp.com', 'Alice Co',  ''],
        ['Alice2', 'same@corp.com', 'Alice2 Co', ''],
    ];

    $this->withToken($this->adminToken)
        ->postJson('/api/admin/onboarding/bulk', ['file' => makeCsvFile($rows)])
        ->assertOk();

    expect(User::where('email', 'same@corp.com')->count())->toBe(1);
});

test('an invite job is dispatched once per created account', function () {
    $rows = [
        ['Alice', 'invite1@corp.com', 'Alice Co', 'agency'],
        ['Bob',   'invite2@corp.com', 'Bob Ltd',  ''],
    ];

    $this->withToken($this->adminToken)
        ->postJson('/api/admin/onboarding/bulk', ['file' => makeCsvFile($rows)])
        ->assertOk();

    Queue::assertPushed(SendBulkInviteJob::class, 2);
});

test('partner_type is stored on the employer record', function () {
    $rows = [['UniName', 'uni@edu.com', 'State University', 'university']];

    $this->withToken($this->adminToken)
        ->postJson('/api/admin/onboarding/bulk', ['file' => makeCsvFile($rows)])
        ->assertOk();

    $user     = User::where('email', 'uni@edu.com')->first();
    $employer = Employer::where('user_id', (string) $user->_id)->first();
    expect($employer->partner_type)->toBe('university');
});

// ── Audit log ────────────────────────────────────────────────────────

test('bulk onboarding writes an audit log entry', function () {
    $rows = [['LogTest', 'logtest@corp.com', 'Log Corp', '']];

    $this->withToken($this->adminToken)
        ->postJson('/api/admin/onboarding/bulk', ['file' => makeCsvFile($rows)])
        ->assertOk();

    $log = AuditLog::where('action', 'bulk_employer_onboarded')->first();
    expect($log)->not->toBeNull()
        ->and($log->metadata['created_count'])->toBe(1);
});
