<?php

// Property-style coverage of the User role model: multi-role checks, the roles
// field always being an array, and the specific role-check methods staying
// consistent with hasRole(). These build users with arbitrary role arrays, which
// the role-specific shared helpers don't cover, so they use the factory directly.

use App\Models\User;

// Property 21: users with multiple roles can be checked correctly.
test('users with multiple roles can be checked correctly', function () {
    $allRoles = ['admin', 'employer', 'employee'];

    for ($i = 0; $i < 20; $i++) {
        $numRoles = rand(1, 3);
        $selectedRoles = array_slice($allRoles, 0, $numRoles);
        shuffle($selectedRoles);

        $user = User::factory()->create(['roles' => $selectedRoles]);

        foreach ($selectedRoles as $role) {
            expect($user->hasRole($role))->toBeTrue("User should have role: {$role}");
        }

        $missingRoles = array_values(array_diff($allRoles, $selectedRoles));
        foreach ($missingRoles as $role) {
            expect($user->hasRole($role))->toBeFalse("User should not have role: {$role}");
        }

        expect($user->hasAnyRole($selectedRoles))->toBeTrue('User should have any of their assigned roles');

        if (! empty($missingRoles)) {
            expect($user->hasAnyRole($missingRoles))->toBeFalse('User should not have any of the missing roles');
        }

        if (count($selectedRoles) > 0 && count($missingRoles) > 0) {
            $mixedRoles = [$selectedRoles[0], $missingRoles[0]];
            expect($user->hasAnyRole($mixedRoles))->toBeTrue('User should have at least one role from mixed set');
        }
    }
});

// Property 22: roles field is always stored and retrieved as an array.
test('roles field is always stored and retrieved as array', function () {
    $allRoles = ['admin', 'employer', 'employee'];

    for ($i = 0; $i < 20; $i++) {
        $numRoles = rand(0, 3);
        $selectedRoles = $numRoles > 0 ? array_slice($allRoles, 0, $numRoles) : [];

        $user = User::factory()->create(['roles' => $selectedRoles]);

        expect($user->roles)->toBeArray('Roles should be an array');
        expect($user->roles)->toBe($selectedRoles, 'Roles should match the assigned values');

        $user->refresh();

        expect($user->roles)->toBeArray('Roles should still be an array after refresh from database');
        expect($user->roles)->toBe($selectedRoles, 'Roles should still match after refresh from database');
    }
});

test('hasAnyRole returns false when given empty array', function () {
    for ($i = 0; $i < 10; $i++) {
        $user = User::factory()->create(['roles' => ['admin', 'employer']]);

        expect($user->hasAnyRole([]))->toBeFalse('hasAnyRole should return false for empty array');
    }
});

test('specific role checking methods are consistent with hasRole', function () {
    $roleChecks = [
        'admin'    => 'isAdmin',
        'employer' => 'isEmployer',
        'employee' => 'isJobSeeker',
    ];

    for ($i = 0; $i < 20; $i++) {
        foreach ($roleChecks as $role => $method) {
            $userWithRole = User::factory()->create(['roles' => [$role]]);
            expect($userWithRole->$method())->toBe(
                $userWithRole->hasRole($role),
                "Method {$method}() should be consistent with hasRole('{$role}')"
            );

            $userWithoutRole = User::factory()->create(['roles' => []]);
            expect($userWithoutRole->$method())->toBe(
                $userWithoutRole->hasRole($role),
                "Method {$method}() should be consistent with hasRole('{$role}') when role is absent"
            );
        }
    }
});
