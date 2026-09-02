<?php

// AuditLogService: immutable, append-only audit log writes.
// Property 10: audit log document count is monotonically non-decreasing.

use App\Models\AuditLog;
use App\Services\AuditLogService;

// ── Writing entries ──────────────────────────────────────────────────

it('writes an audit log entry with all required fields', function () {
    AuditLogService::log(
        action:     'employer_approved',
        actorId:    'actor-123',
        actorName:  'Admin User',
        targetId:   'target-456',
        targetType: 'Employer',
        metadata:   ['note' => 'test']
    );

    $log = AuditLog::first();
    expect($log)->not->toBeNull()
        ->and($log->action)->toBe('employer_approved')
        ->and($log->actor_id)->toBe('actor-123')
        ->and($log->actor_name)->toBe('Admin User')
        ->and($log->target_id)->toBe('target-456')
        ->and($log->target_type)->toBe('Employer')
        ->and($log->metadata)->toBe(['note' => 'test'])
        ->and($log->created_at)->not->toBeNull();
});

it('never sets updated_at on audit log entries', function () {
    AuditLogService::log('broadcast_sent', 'actor-1', 'Admin', null, null);

    expect(AuditLog::first()->updated_at)->toBeNull();
});

it('accepts nullable target fields', function () {
    AuditLogService::log('broadcast_sent', 'actor-1', 'Admin');

    $log = AuditLog::first();
    expect($log->target_id)->toBeNull()
        ->and($log->target_type)->toBeNull();
});

// ── Property 10: count is monotonically non-decreasing ───────────────

it('document count increases by exactly 1 per action and never decreases', function () {
    $counts = [];

    foreach (range(1, 5) as $i) {
        $before = AuditLog::count();
        AuditLogService::log("action_{$i}", "actor-{$i}", "Admin {$i}");
        $after = AuditLog::count();

        expect($after)->toBe($before + 1);
        $counts[] = $after;
    }

    for ($i = 1; $i < count($counts); $i++) {
        expect($counts[$i])->toBeGreaterThanOrEqual($counts[$i - 1]);
    }
});

it('existing audit log entries are not modified after creation', function () {
    AuditLogService::log('employer_approved', 'actor-1', 'Admin', 'target-1', 'Employer');

    $log     = AuditLog::first();
    $id      = (string) $log->_id;
    $created = $log->created_at->toISOString();

    AuditLogService::log('broadcast_sent', 'actor-2', 'Admin2');
    AuditLogService::log('broadcast_sent', 'actor-3', 'Admin3');

    $reloaded = AuditLog::find($id);
    expect($reloaded->action)->toBe('employer_approved')
        ->and($reloaded->created_at->toISOString())->toBe($created);
});
