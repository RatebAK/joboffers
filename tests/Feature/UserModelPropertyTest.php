<?php

use App\Models\User;

beforeEach(function () {
    // Clean up users collection before each test
    User::truncate();
});

afterEach(function () {
    // Clean up users collection after each test
    User::truncate();
});

/**
 * Property 21: Multi-role access
 * Test that users with multiple roles can be checked correctly
 * Validates: Requirements 8.1, 8.2, 8.4
 */
test('property 21: users with multiple roles can be checked correctly', function () {
    $allRoles = ['admin', 'employer', 'employee'];
    
    // Test with 100 iterations for property-based testing
    for ($i = 0; $i < 20; $i++) {
        // Generate random combination of roles (1 to 3 roles)
        $numRoles = rand(1, 3);
        $selectedRoles = array_slice($allRoles, 0, $numRoles);
        shuffle($selectedRoles);
        
        // Create user with random roles
        $user = User::factory()->create([
            'roles' => $selectedRoles
        ]);
        
        // Test hasRole for each role the user has
        foreach ($selectedRoles as $role) {
            expect($user->hasRole($role))->toBeTrue(
                "User should have role: {$role}"
            );
        }
        
        // Test hasRole for roles the user doesn't have
        $missingRoles = array_values(array_diff($allRoles, $selectedRoles));
        foreach ($missingRoles as $role) {
            expect($user->hasRole($role))->toBeFalse(
                "User should not have role: {$role}"
            );
        }
        
        // Test hasAnyRole with various combinations
        expect($user->hasAnyRole($selectedRoles))->toBeTrue(
            "User should have any of their assigned roles"
        );
        
        if (!empty($missingRoles)) {
            expect($user->hasAnyRole($missingRoles))->toBeFalse(
                "User should not have any of the missing roles"
            );
        }
        
        // Test hasAnyRole with mixed roles (some user has, some doesn't)
        if (count($selectedRoles) > 0 && count($missingRoles) > 0) {
            $mixedRoles = array_merge(
                [$selectedRoles[0]], 
                [$missingRoles[0]]
            );
            expect($user->hasAnyRole($mixedRoles))->toBeTrue(
                "User should have at least one role from mixed set"
            );
        }
    }
});

/**
 * Property 22: Roles stored as array
 * Test that roles field is always an array type
 * Validates: Requirements 8.1, 8.2, 8.4
 */
test('property 22: roles field is always stored and retrieved as array', function () {
    $allRoles = ['admin', 'employer', 'employee'];
    
    // Test with 100 iterations for property-based testing
    for ($i = 0; $i < 20; $i++) {
        // Generate random combination of roles
        $numRoles = rand(0, 3); // Including 0 for empty array test
        $selectedRoles = $numRoles > 0 ? array_slice($allRoles, 0, $numRoles) : [];
        
        // Create user with roles
        $user = User::factory()->create([
            'roles' => $selectedRoles
        ]);
        
        // Verify roles is an array
        expect($user->roles)->toBeArray(
            "Roles should be an array"
        );
        
        // Verify the roles match what was set
        expect($user->roles)->toBe($selectedRoles,
            "Roles should match the assigned values"
        );
        
        // Refresh from database and verify it's still an array
        $user->refresh();
        expect($user->roles)->toBeArray(
            "Roles should still be an array after refresh from database"
        );
        
        expect($user->roles)->toBe($selectedRoles,
            "Roles should still match after refresh from database"
        );
    }
});

/**
 * Additional property test: hasAnyRole with empty array
 * Test edge case where hasAnyRole is called with empty array
 */
test('hasAnyRole returns false when given empty array', function () {
    for ($i = 0; $i < 10; $i++) {
        $user = User::factory()->create([
            'roles' => ['admin', 'employer']
        ]);
        
        expect($user->hasAnyRole([]))->toBeFalse(
            "hasAnyRole should return false for empty array"
        );
    }
});

/**
 * Additional property test: Role checking methods consistency
 * Test that specific role checking methods are consistent with hasRole
 */
test('specific role checking methods are consistent with hasRole', function () {
    $roleChecks = [
        'admin' => 'isAdmin',
        'employer' => 'isEmployer',
        'employee' => 'isJobSeeker',
    ];
    
    for ($i = 0; $i < 20; $i++) {
        foreach ($roleChecks as $role => $method) {
            // Test with role present
            $userWithRole = User::factory()->create([
                'roles' => [$role]
            ]);
            
            expect($userWithRole->$method())->toBe($userWithRole->hasRole($role),
                "Method {$method}() should be consistent with hasRole('{$role}')"
            );
            
            // Test with role absent
            $userWithoutRole = User::factory()->create([
                'roles' => []
            ]);
            
            expect($userWithoutRole->$method())->toBe($userWithoutRole->hasRole($role),
                "Method {$method}() should be consistent with hasRole('{$role}') when role is absent"
            );
        }
    }
});
