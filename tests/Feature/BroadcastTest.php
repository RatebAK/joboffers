<?php

// Platform broadcast endpoint (POST /api/admin/broadcast).
// Property 8: broadcast recipient set matches the audience definition.

use App\Jobs\SendBroadcastJob;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    [$this->admin, $this->adminToken] = userWithToken('admin');
});

// ── Auth / validation ────────────────────────────────────────────────

test('unauthenticated user cannot broadcast', function () {
    $this->postJson('/api/admin/broadcast')->assertUnauthorized();
});

test('non-admin cannot broadcast', function () {
    $this->withToken(tokenFor('employee'))
        ->postJson('/api/admin/broadcast', [
            'subject'  => 'Test',
            'body'     => 'Hello',
            'audience' => 'all',
        ])->assertForbidden();
});

test('broadcast requires a subject', function () {
    $this->withToken($this->adminToken)
        ->postJson('/api/admin/broadcast', [
            'body'     => 'Hello',
            'audience' => 'all',
        ])->assertStatus(422);
});

test('broadcast requires a body', function () {
    $this->withToken($this->adminToken)
        ->postJson('/api/admin/broadcast', [
            'subject'  => 'Hi',
            'audience' => 'all',
        ])->assertStatus(422);
});

// ── Property 8: recipient set matches audience ───────────────────────

test('audience=employees targets only employee users', function () {
    createUser('employee');
    createUser('employee');
    $employer = createUser('employer');

    $resp = $this->withToken($this->adminToken)
        ->postJson('/api/admin/broadcast', [
            'subject'  => 'Hello',
            'body'     => 'Body',
            'audience' => 'employees',
        ])->assertOk();

    expect($resp->json('recipient_count'))->toBeGreaterThanOrEqual(2);

    Queue::assertPushed(SendBroadcastJob::class, fn ($job) => $job->userId !== (string) $employer->_id);
});

test('audience=employers targets only employer users', function () {
    createUser('employee');
    createUser('employer');
    createUser('employer');

    $resp = $this->withToken($this->adminToken)
        ->postJson('/api/admin/broadcast', [
            'subject'  => 'Hello',
            'body'     => 'Body',
            'audience' => 'employers',
        ])->assertOk();

    expect($resp->json('recipient_count'))->toBeGreaterThanOrEqual(2);
});

test('audience=all excludes admin users', function () {
    $admin2 = createUser('admin');
    createUser('employee');

    $this->withToken($this->adminToken)
        ->postJson('/api/admin/broadcast', [
            'subject'  => 'Platform news',
            'body'     => 'Body text',
            'audience' => 'all',
        ])->assertOk();

    Queue::assertNotPushed(
        SendBroadcastJob::class,
        fn ($job) => $job->userId === (string) $this->admin->_id || $job->userId === (string) $admin2->_id
    );
});

test('user_ids targets only those specific users', function () {
    $u1 = createUser('employee');
    $u2 = createUser('employee');
    $u3 = createUser('employee');

    $resp = $this->withToken($this->adminToken)
        ->postJson('/api/admin/broadcast', [
            'subject'  => 'Direct message',
            'body'     => 'Body',
            'user_ids' => [(string) $u1->_id, (string) $u2->_id],
        ])->assertOk();

    expect($resp->json('recipient_count'))->toBe(2);
    Queue::assertPushed(SendBroadcastJob::class, 2);
    Queue::assertNotPushed(SendBroadcastJob::class, fn ($job) => $job->userId === (string) $u3->_id);
});

// ── Response format ──────────────────────────────────────────────────

test('broadcast returns queued status and recipient_count', function () {
    $resp = $this->withToken($this->adminToken)
        ->postJson('/api/admin/broadcast', [
            'subject'  => 'Test',
            'body'     => 'Body',
            'audience' => 'all',
        ])->assertOk();

    expect($resp->json('status'))->toBe('queued')
        ->and($resp->json('recipient_count'))->toBeInt();
});

// ── Audit log ────────────────────────────────────────────────────────

test('broadcast writes an audit log entry', function () {
    $this->withToken($this->adminToken)
        ->postJson('/api/admin/broadcast', [
            'subject'  => 'Campaign',
            'body'     => 'Re-engage!',
            'audience' => 'employees',
        ])->assertOk();

    $log = AuditLog::where('action', 'broadcast_sent')->first();
    expect($log)->not->toBeNull()
        ->and($log->metadata['subject'])->toBe('Campaign');
});
