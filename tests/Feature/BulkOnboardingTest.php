<?php

// Feature: admin-business-intelligence, Property 6: Bulk onboarding row accounting invariant

use App\Jobs\SendBulkInviteJob;
use App\Models\AuditLog;
use App\Models\Employer;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;

// ── Helpers ──────────────────────────────────────────────────────────

function bulkAdminToken(): array
{
    $admin = User::factory()->admin()->create();
    $token = auth('api')->login($admin);
    return [$admin, $token];
}

function makeCsvFile(array $rows, string $header = "name,email,company_name,partner_type"): UploadedFile
{
    $lines = [$header];
    foreach ($rows as $row) {
        $lines[] = implode(',', $row);
    }
    $content = implode("\n", $lines);
    $tmpPath = tempnam(sys_get_temp_dir(), 'bulk_') . '.csv';
    file_put_contents($tmpPath, $content);
    return new UploadedFile($tmpPath, 'employers.csv', 'text/csv', null, true);
}

// ── Validation ────────────────────────────────────────────────────────

test('bulk onboarding returns 401 without token', function () {
    $this->postJson('/api/admin/onboarding/bulk')->assertStatus(401);
});

test('bulk onboarding returns 403 for non-admin', function () {
    $user  = User::factory()->employee()->create();
    $token = auth('api')->login($user);
    $file  = makeCsvFile([['Acme', 'acme@test.com', 'Acme Corp', '']]);

    $this->withToken($token)
        ->postJson('/api/admin/onboarding/bulk', ['file' => $file])
        ->assertStatus(403);

    $user->delete();
});

test('bulk onboarding returns 422 when no file provided', function () {
    [$admin, $token] = bulkAdminToken();

    $resp = $this->withToken($token)->postJson('/api/admin/onboarding/bulk')
        ->assertStatus(422);

    // Laravel validation errors shape may vary — just confirm it's a 422
    expect($resp->status())->toBe(422);

    $admin->delete();
});

test('bulk onboarding returns 422 when csv is missing required columns', function () {
    [$admin, $token] = bulkAdminToken();
    // CSV missing company_name column
    $file = makeCsvFile([['Acme', 'acme@test.com']], 'name,email');

    $this->withToken($token)
        ->postJson('/api/admin/onboarding/bulk', ['file' => $file])
        ->assertStatus(422)
        ->assertJson(['message' => 'CSV is missing required column: company_name']);

    $admin->delete();
});

// ── Property 6: Row accounting invariant ─────────────────────────────

test('created plus skipped equals total rows', function () {
    [$admin, $token] = bulkAdminToken();
    Queue::fake();

    $rows = [
        ['Alice', 'alice@corp.com',   'Alice Co', 'agency'],
        ['Bob',   'bob@corp.com',     'Bob Ltd',  ''],
        ['Carol', 'carol@corp.com',   'Carol Inc','university'],
    ];
    $file = makeCsvFile($rows);

    $resp = $this->withToken($token)
        ->postJson('/api/admin/onboarding/bulk', ['file' => $file])
        ->assertStatus(200);

    $data = $resp->json();
    expect($data['created'] + $data['skipped'])->toBe($data['total_rows'])->toBe(3);

    // Cleanup
    User::whereIn('email', ['alice@corp.com','bob@corp.com','carol@corp.com'])->each(fn ($u) => $u->delete());
    Employer::all()->each(fn ($e) => $e->delete());
    AuditLog::truncate();
    $admin->delete();
});

test('duplicate email rows are skipped with email_exists reason', function () {
    [$admin, $token] = bulkAdminToken();
    Queue::fake();

    $dupEmail = 'dup_' . uniqid() . '@corp.com';
    $existing = User::factory()->employee()->create(['email' => $dupEmail]);

    $rows = [
        ['Alice', 'alice_' . uniqid() . '@corp.com', 'Alice Co', ''],
        ['Dup', $dupEmail, 'Dup Corp', ''],  // duplicate
    ];
    $file = makeCsvFile($rows);

    $resp = $this->withToken($token)
        ->postJson('/api/admin/onboarding/bulk', ['file' => $file])
        ->assertStatus(200);

    $data = $resp->json();
    expect($data['created'])->toBe(1);
    expect($data['skipped'])->toBe(1);

    $skippedEmails  = collect($data['skipped_rows'])->pluck('email')->toArray();
    $skippedReasons = collect($data['skipped_rows'])->pluck('reason')->toArray();
    expect($skippedEmails)->toContain($dupEmail);
    expect($skippedReasons)->toContain('email_exists');

    User::where('email', $rows[0][1])->each(fn ($u) => $u->delete());
    $existing->delete();
    Employer::all()->each(fn ($e) => $e->delete());
    AuditLog::truncate();
    $admin->delete();
});

test('no duplicate user emails exist after processing', function () {
    [$admin, $token] = bulkAdminToken();
    Queue::fake();

    // Send same email twice in the same CSV
    $rows = [
        ['Alice', 'same@corp.com', 'Alice Co', ''],
        ['Alice2', 'same@corp.com', 'Alice2 Co', ''],  // duplicate in same upload
    ];
    $file = makeCsvFile($rows);

    $this->withToken($token)
        ->postJson('/api/admin/onboarding/bulk', ['file' => $file])
        ->assertStatus(200);

    // Only one user should exist with this email
    $count = User::where('email', 'same@corp.com')->count();
    expect($count)->toBe(1);

    User::where('email', 'same@corp.com')->each(fn ($u) => $u->delete());
    Employer::all()->each(fn ($e) => $e->delete());
    AuditLog::truncate();
    $admin->delete();
});

test('SendBulkInviteJob is dispatched once per created account', function () {
    [$admin, $token] = bulkAdminToken();
    Queue::fake();

    $rows = [
        ['Alice', 'invite1@corp.com', 'Alice Co', 'agency'],
        ['Bob',   'invite2@corp.com', 'Bob Ltd',  ''],
    ];
    $file = makeCsvFile($rows);

    $this->withToken($token)
        ->postJson('/api/admin/onboarding/bulk', ['file' => $file])
        ->assertStatus(200);

    Queue::assertPushed(SendBulkInviteJob::class, 2);

    User::whereIn('email', ['invite1@corp.com','invite2@corp.com'])->each(fn ($u) => $u->delete());
    Employer::all()->each(fn ($e) => $e->delete());
    AuditLog::truncate();
    $admin->delete();
});

test('partner_type is stored on employer record', function () {
    [$admin, $token] = bulkAdminToken();
    Queue::fake();

    $rows = [['UniName', 'uni@edu.com', 'State University', 'university']];
    $file = makeCsvFile($rows);

    $this->withToken($token)
        ->postJson('/api/admin/onboarding/bulk', ['file' => $file])
        ->assertStatus(200);

    $user     = User::where('email', 'uni@edu.com')->first();
    $employer = Employer::where('user_id', (string) $user->_id)->first();
    expect($employer->partner_type)->toBe('university');

    $user->delete();
    $employer->delete();
    AuditLog::truncate();
    $admin->delete();
});

test('bulk onboarding writes audit log entry', function () {
    [$admin, $token] = bulkAdminToken();
    Queue::fake();
    AuditLog::truncate();

    $rows = [['LogTest', 'logtest@corp.com', 'Log Corp', '']];
    $file = makeCsvFile($rows);

    $this->withToken($token)
        ->postJson('/api/admin/onboarding/bulk', ['file' => $file])
        ->assertStatus(200);

    $log = AuditLog::where('action', 'bulk_employer_onboarded')->first();
    expect($log)->not->toBeNull();
    expect($log->metadata['created_count'])->toBe(1);

    User::where('email', 'logtest@corp.com')->each(fn ($u) => $u->delete());
    Employer::all()->each(fn ($e) => $e->delete());
    AuditLog::truncate();
    $admin->delete();
});
