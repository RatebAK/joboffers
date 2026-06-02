<?php

use App\Models\User;

test('user can view their own profile', function () {
    $seekerRes = $this->postJson('/api/auth/register', [
        'name'     => 'Profile View Test',
        'email'    => 'profile_view_' . uniqid() . '@test.com',
        'password' => 'Password@123',
        'password_confirmation' => 'Password@123',
        'role'    => 'employee',
    ]);
    $seekerToken = $seekerRes->json('access_token');
    $seekerId    = $seekerRes->json('user.id');

    $res = $this->withHeader('Authorization', "Bearer $seekerToken")
        ->getJson("/api/users/$seekerId")
        ->assertOk();

    expect($res->json('user.name'))->toBe('Profile View Test');
    expect($res->json('user'))->toHaveKey('id');
    expect($res->json('user'))->not->toHaveKey('password');
});

test('admin can list all users', function () {
    $admin = $this->postJson('/api/auth/register', [
        'name'     => 'Admin List Test',
        'email'    => 'admin_list_' . uniqid() . '@test.com',
        'password' => 'Password@123',
        'password_confirmation' => 'Password@123',
        'role'    => 'admin',
    ]);
    $adminToken = $admin->json('access_token');

    $res = $this->withHeader('Authorization', "Bearer $adminToken")
        ->getJson('/api/admin/users')
        ->assertOk();

    expect($res->json())->toHaveKeys(['data', 'current_page', 'per_page', 'total']);
});

test('admin can list job seekers', function () {
    $admin = $this->postJson('/api/auth/register', [
        'name'     => 'Admin Seekers Test',
        'email'    => 'admin_seekers_' . uniqid() . '@test.com',
        'password' => 'Password@123',
        'password_confirmation' => 'Password@123',
        'role'    => 'admin',
    ]);
    $adminToken = $admin->json('access_token');

    $res = $this->withHeader('Authorization', "Bearer $adminToken")
        ->getJson('/api/admin/users/seekers')
        ->assertOk();

    expect($res->json())->toHaveKeys(['data', 'current_page', 'per_page', 'total']);
});

test('admin can list employers', function () {
    $admin = $this->postJson('/api/auth/register', [
        'name'     => 'Admin Employers Test',
        'email'    => 'admin_employers_' . uniqid() . '@test.com',
        'password' => 'Password@123',
        'password_confirmation' => 'Password@123',
        'role'    => 'admin',
    ]);
    $adminToken = $admin->json('access_token');

    $res = $this->withHeader('Authorization', "Bearer $adminToken")
        ->getJson('/api/admin/users/employers')
        ->assertOk();

    expect($res->json())->toHaveKeys(['data', 'current_page', 'per_page', 'total']);
});

test('non-admin cannot access admin endpoints', function () {
    $seekerRes = $this->postJson('/api/auth/register', [
        'name'     => 'Non Admin Test',
        'email'    => 'non_admin_' . uniqid() . '@test.com',
        'password' => 'Password@123',
        'password_confirmation' => 'Password@123',
        'role'    => 'employee',
    ]);
    $seekerToken = $seekerRes->json('access_token');

    $this->withHeader('Authorization', "Bearer $seekerToken")
        ->getJson('/api/admin/users')
        ->assertForbidden();
});
