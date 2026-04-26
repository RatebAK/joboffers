<?php

use App\Models\User;

/**
 * Unit tests for User model role methods
 * These tests verify specific examples and edge cases
 * Validates: Requirements 8.1, 8.2, 8.4
 */

test('isAdmin returns true for users with admin role', function () {
    $user = new User();
    $user->roles = ['admin'];
    
    expect($user->isAdmin())->toBeTrue();
});

test('isAdmin returns false for users without admin role', function () {
    $user = new User();
    $user->roles = ['employer', 'employee'];
    
    expect($user->isAdmin())->toBeFalse();
});

test('isAdmin returns false for users with empty roles', function () {
    $user = new User();
    $user->roles = [];
    
    expect($user->isAdmin())->toBeFalse();
});

test('isAdmin returns false for users with null roles', function () {
    $user = new User();
    $user->roles = null;
    
    expect($user->isAdmin())->toBeFalse();
});

test('hasAnyRole returns true when user has one of the specified roles', function () {
    $user = new User();
    $user->roles = ['employer'];
    
    expect($user->hasAnyRole(['admin', 'employer']))->toBeTrue();
});

test('hasAnyRole returns true when user has multiple specified roles', function () {
    $user = new User();
    $user->roles = ['admin', 'employer', 'employee'];
    
    expect($user->hasAnyRole(['admin', 'employer']))->toBeTrue();
});

test('hasAnyRole returns false when user has none of the specified roles', function () {
    $user = new User();
    $user->roles = ['employee'];
    
    expect($user->hasAnyRole(['admin', 'employer']))->toBeFalse();
});

test('hasAnyRole returns false when user has empty roles array', function () {
    $user = new User();
    $user->roles = [];
    
    expect($user->hasAnyRole(['admin', 'employer']))->toBeFalse();
});

test('hasAnyRole returns false when user has null roles', function () {
    $user = new User();
    $user->roles = null;
    
    expect($user->hasAnyRole(['admin', 'employer']))->toBeFalse();
});

test('hasAnyRole returns false when given empty array', function () {
    $user = new User();
    $user->roles = ['admin', 'employer'];
    
    expect($user->hasAnyRole([]))->toBeFalse();
});

test('hasRole returns true for exact role match', function () {
    $user = new User();
    $user->roles = ['admin', 'employer'];
    
    expect($user->hasRole('admin'))->toBeTrue();
    expect($user->hasRole('employer'))->toBeTrue();
});

test('hasRole returns false for role not in array', function () {
    $user = new User();
    $user->roles = ['admin'];
    
    expect($user->hasRole('employer'))->toBeFalse();
});

test('hasRole returns false for empty roles array', function () {
    $user = new User();
    $user->roles = [];
    
    expect($user->hasRole('admin'))->toBeFalse();
});

test('hasRole returns false for null roles', function () {
    $user = new User();
    $user->roles = null;
    
    expect($user->hasRole('admin'))->toBeFalse();
});

test('isEmployer returns true for users with employer role', function () {
    $user = new User();
    $user->roles = ['employer'];
    
    expect($user->isEmployer())->toBeTrue();
});

test('isEmployer returns false for users without employer role', function () {
    $user = new User();
    $user->roles = ['admin', 'employee'];
    
    expect($user->isEmployer())->toBeFalse();
});

test('isJobSeeker returns true for users with employee role', function () {
    $user = new User();
    $user->roles = ['employee'];
    
    expect($user->isJobSeeker())->toBeTrue();
});

test('isJobSeeker returns false for users without employee role', function () {
    $user = new User();
    $user->roles = ['admin', 'employer'];
    
    expect($user->isJobSeeker())->toBeFalse();
});

test('user can have multiple roles simultaneously', function () {
    $user = new User();
    $user->roles = ['admin', 'employer', 'employee'];
    
    expect($user->isAdmin())->toBeTrue();
    expect($user->isEmployer())->toBeTrue();
    expect($user->hasRole('employee'))->toBeTrue();
});

test('hasAnyRole works correctly with single role in array', function () {
    $user = new User();
    $user->roles = ['employer'];
    
    expect($user->hasAnyRole(['employer']))->toBeTrue();
    expect($user->hasAnyRole(['admin']))->toBeFalse();
});

test('hasAnyRole is case sensitive', function () {
    $user = new User();
    $user->roles = ['admin'];
    
    expect($user->hasAnyRole(['Admin']))->toBeFalse();
    expect($user->hasAnyRole(['ADMIN']))->toBeFalse();
});

test('hasRole is case sensitive', function () {
    $user = new User();
    $user->roles = ['admin'];
    
    expect($user->hasRole('Admin'))->toBeFalse();
    expect($user->hasRole('ADMIN'))->toBeFalse();
});
