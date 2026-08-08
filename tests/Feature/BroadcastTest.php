<?php

// Feature: admin-business-intelligence, Property 8: Broadcast recipient set matches audience definition

use App\Jobs\SendBroadcastJob;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

// ── Helpers ──────────────────────────────────────────────────────────

function broadcastAdmin(): array
{
    $admin = User::factory()->admin()->create();
    $token = auth('api')->login($admin);
    return [$admin, $token];
}

// ── Auth / validation ─────────────────────────────────────────────────

test('broadcast returns 401 without token', function () {
    $this->postJson('/api/admin/broadcast')->assertStatus(401);
});

test('broadcast returns 403 for non-admin', function () {
    $user  = User::factory()->employee()->create();
    $token = auth('api')->login($user);
    $this->withToken($token)->postJson('/api/admin/broadcast', [
        'subject'  => 'Test',
        'body'     => 'Hello',
        'audience' => 'all',
    ])->assertStatus(403);
    $user->delete();
});

test('broadcast returns 422 when subject is missing', function () {
    [$admin, $token] = broadcastAdmin();
    $this->withToken($token)->postJson('/api/admin/broadcast', [
        'body'     => 'Hello',
        'audience' => 'all',
    ])->assertStatus(422);
    $admin->delete();
});

test('broadcast returns 422 when body is missing', function () {
    [$admin, $token] = broadcastAdmin();
    $this->withToken($token)->postJson('/api/admin/broadcast', [
        'subject'  => 'Hi',
        'audience' => 'all',
    ])->assertStatus(422);
    $admin->delete();
});

// ── Property 8: Recipient set matches audience definition ─────────────

test('broadcast with audience=employees targets only employee users', function () {
    Queue::fake();
    [$admin, $token] = broadcastAdmin();

    $emp1    = User::factory()->employee()->create();
    $emp2    = User::factory()->employee()->create();
    $employer = User::factory()->employer()->create();

    $resp = $this->withToken($token)->postJson('/api/admin/broadcast', [
        'subject'  => 'Hello',
        'body'     => 'Body',
        'audience' => 'employees',
    ])->assertStatus(200);

    $count = $resp->json('recipient_count');
    // Should include both employees but not the employer
    expect($count)->toBeGreaterThanOrEqual(2);

    // Verify all dispatched jobs are for employee users
    Queue::assertPushed(SendBroadcastJob::class, function ($job) use ($employer) {
        return $job->userId !== (string) $employer->_id;
    });

    $emp1->delete(); $emp2->delete(); $employer->delete(); $admin->delete();
    AuditLog::truncate();
});

test('broadcast with audience=employers targets only employer users', function () {
    Queue::fake();
    [$admin, $token] = broadcastAdmin();

    $seeker   = User::factory()->employee()->create();
    $employer1 = User::factory()->employer()->create();
    $employer2 = User::factory()->employer()->create();

    $resp = $this->withToken($token)->postJson('/api/admin/broadcast', [
        'subject'  => 'Hello',
        'body'     => 'Body',
        'audience' => 'employers',
    ])->assertStatus(200);

    expect($resp->json('recipient_count'))->toBeGreaterThanOrEqual(2);

    $seeker->delete(); $employer1->delete(); $employer2->delete(); $admin->delete();
    AuditLog::truncate();
});

test('broadcast with audience=all excludes admin users', function () {
    Queue::fake();
    [$admin, $token] = broadcastAdmin();
    $admin2 = User::factory()->admin()->create();
    $seeker = User::factory()->employee()->create();

    $this->withToken($token)->postJson('/api/admin/broadcast', [
        'subject'  => 'Platform news',
        'body'     => 'Body text',
        'audience' => 'all',
    ])->assertStatus(200);

    // Neither admin should receive the broadcast
    Queue::assertNotPushed(SendBroadcastJob::class, fn ($job) =>
        $job->userId === (string) $admin->_id || $job->userId === (string) $admin2->_id
    );

    $admin2->delete(); $seeker->delete(); $admin->delete();
    AuditLog::truncate();
});

test('broadcast with user_ids targets only those specific users', function () {
    Queue::fake();
    [$admin, $token] = broadcastAdmin();

    $u1 = User::factory()->employee()->create();
    $u2 = User::factory()->employee()->create();
    $u3 = User::factory()->employee()->create();

    $resp = $this->withToken($token)->postJson('/api/admin/broadcast', [
        'subject'  => 'Direct message',
        'body'     => 'Body',
        'user_ids' => [(string) $u1->_id, (string) $u2->_id],
    ])->assertStatus(200);

    expect($resp->json('recipient_count'))->toBe(2);
    Queue::assertPushed(SendBroadcastJob::class, 2);
    Queue::assertNotPushed(SendBroadcastJob::class, fn ($job) => $job->userId === (string) $u3->_id);

    $u1->delete(); $u2->delete(); $u3->delete(); $admin->delete();
    AuditLog::truncate();
});

// ── Response format ───────────────────────────────────────────────────

test('broadcast returns queued status and recipient_count', function () {
    Queue::fake();
    [$admin, $token] = broadcastAdmin();

    $resp = $this->withToken($token)->postJson('/api/admin/broadcast', [
        'subject'  => 'Test',
        'body'     => 'Body',
        'audience' => 'all',
    ])->assertStatus(200);

    expect($resp->json('status'))->toBe('queued');
    expect($resp->json('recipient_count'))->toBeInt();

    $admin->delete();
    AuditLog::truncate();
});

// ── Audit log ─────────────────────────────────────────────────────────

test('broadcast writes audit log entry', function () {
    Queue::fake();
    AuditLog::truncate();
    [$admin, $token] = broadcastAdmin();

    $this->withToken($token)->postJson('/api/admin/broadcast', [
        'subject'  => 'Campaign',
        'body'     => 'Re-engage!',
        'audience' => 'employees',
    ])->assertStatus(200);

    $log = AuditLog::where('action', 'broadcast_sent')->first();
    expect($log)->not->toBeNull();
    expect($log->metadata['subject'])->toBe('Campaign');

    $admin->delete();
    AuditLog::truncate();
});
