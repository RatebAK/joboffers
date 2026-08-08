<?php

use App\Models\AuditLog;
use App\Models\Employer;
use App\Models\User;

function approvalAdmin(): array
{
    $admin = User::factory()->admin()->create();
    $token = auth('api')->login($admin);
    return [$admin, $token];
}

function pendingEmployer(): array
{
    $user = User::factory()->create(['roles' => ['employee']]);
    $employer = Employer::create([
        'user_id'       => (string) $user->_id,
        'status'        => 'pending',
        'document_path' => 'docs/test.pdf',
        'document_name' => 'test.pdf',
    ]);
    return [$user, $employer];
}

test('approving employer creates employer_approved audit log entry', function () {
    [$admin, $token] = approvalAdmin();
    AuditLog::truncate();
    [$empUser, $employer] = pendingEmployer();

    $this->withToken($token)
        ->postJson("/api/admin/employers/{$employer->_id}/approve")
        ->assertStatus(200);

    $log = AuditLog::where('action', 'employer_approved')
        ->where('target_id', (string) $employer->_id)
        ->first();

    expect($log)->not->toBeNull();
    expect($log->target_type)->toBe('Employer');

    $employer->delete();
    $empUser->delete();
    AuditLog::truncate();
    $admin->delete();
});

test('rejecting employer creates employer_rejected audit log entry', function () {
    [$admin, $token] = approvalAdmin();
    AuditLog::truncate();
    [$empUser, $employer] = pendingEmployer();

    $this->withToken($token)
        ->postJson("/api/admin/employers/{$employer->_id}/reject", [
            'review_notes' => 'Insufficient docs',
        ])
        ->assertStatus(200);

    $log = AuditLog::where('action', 'employer_rejected')
        ->where('target_id', (string) $employer->_id)
        ->first();

    expect($log)->not->toBeNull();

    $employer->delete();
    $empUser->delete();
    AuditLog::truncate();
    $admin->delete();
});
