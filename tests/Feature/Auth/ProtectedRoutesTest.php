<?php

// =============================================================================
// ProtectedRoutesTest — role middleware enforced at the HTTP routing layer.
//
// A 200/405/500 all indicate the request passed the role gate (the controller
// may still fail for unrelated reasons); 403 means the gate blocked it.
// =============================================================================

/** True when the response passed the role middleware (was not blocked with 403/401). */
function passedRoleGate(int $status): bool
{
    return in_array($status, [200, 405, 500], true);
}

test('an admin can reach an admin-only route', function () {
    $status = $this->withToken(tokenFor('admin'))->getJson('/api/admin/employers')->status();

    expect(passedRoleGate($status))->toBeTrue();
});

test('a non-admin is forbidden from an admin-only route', function () {
    $this->withToken(tokenFor('employee'))
        ->getJson('/api/admin/employers')
        ->assertForbidden()
        ->assertJsonPath('error', 'Forbidden');
});

test('an employer can reach an employer-only route', function () {
    $status = $this->withToken(tokenFor('employer'))->getJson('/api/employer/jobs')->status();

    expect(passedRoleGate($status))->toBeTrue();
});

test('a non-employer is forbidden from an employer-only route', function () {
    $this->withToken(tokenFor('employee'))
        ->getJson('/api/employer/jobs')
        ->assertForbidden()
        ->assertJsonPath('error', 'Forbidden');
});

test('an employee can reach an employee-only route', function () {
    $status = $this->withToken(tokenFor('employee'))->getJson('/api/job-seeker/profile')->status();

    expect(passedRoleGate($status))->toBeTrue();
});

test('a non-employee is forbidden from an employee-only route', function () {
    $this->withToken(tokenFor('employer'))
        ->getJson('/api/job-seeker/profile')
        ->assertForbidden()
        ->assertJsonPath('error', 'Forbidden');
});

test('an admin can reach employer and employee routes', function () {
    $token = tokenFor('admin');

    expect(passedRoleGate($this->withToken($token)->getJson('/api/employer/jobs')->status()))->toBeTrue()
        ->and(passedRoleGate($this->withToken($token)->getJson('/api/job-seeker/profile')->status()))->toBeTrue();
});

test('a multi-role user can reach routes for each of their roles but not others', function () {
    $token = tokenFor('employer', ['roles' => ['employer', 'employee'], 'is_employer' => true]);

    expect(passedRoleGate($this->withToken($token)->getJson('/api/employer/jobs')->status()))->toBeTrue()
        ->and(passedRoleGate($this->withToken($token)->getJson('/api/job-seeker/profile')->status()))->toBeTrue();

    $this->withToken($token)->getJson('/api/admin/employers')->assertForbidden();
});

test('unauthenticated requests to protected routes return 401', function () {
    $this->getJson('/api/admin/employers')->assertUnauthorized();
    $this->getJson('/api/employer/jobs')->assertUnauthorized();
    $this->getJson('/api/job-seeker/profile')->assertUnauthorized();
});
