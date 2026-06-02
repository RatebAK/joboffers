<?php

use App\Models\User;

test('admin analytics endpoint is accessible', function () {
    $adminRes = $this->postJson('/api/auth/register', [
        'name'     => 'Admin Analytics Test',
        'email'    => 'admin_analytics_' . uniqid() . '@test.com',
        'password' => 'Password@123',
        'password_confirmation' => 'Password@123',
        'role'    => 'admin',
    ]);
    
    $adminRes->assertStatus(201);
    $adminToken = $adminRes->json('access_token');

    $res = $this->withHeader('Authorization', "Bearer $adminToken")
        ->getJson('/api/admin/analytics')
        ->assertOk();

    expect($res->json())->toHaveKeys(['users', 'jobs', 'applications', 'offers', 'companies']);
});

test('employer analytics endpoint is accessible', function () {
    $empRes = $this->postJson('/api/auth/register', [
        'name'     => 'Employer Analytics Test',
        'email'    => 'emp_analytics_' . uniqid() . '@test.com',
        'password' => 'Password@123',
        'password_confirmation' => 'Password@123',
        'role'    => 'employer',
    ]);
    
    $empRes->assertStatus(201);
    $empToken = $empRes->json('access_token');
    
    // Set employer as approved
    $empId = $empRes->json('user.id');
    $employer = User::find($empId);
    $employer->is_employer = true;
    $employer->save();

    $res = $this->withHeader('Authorization', "Bearer $empToken")
        ->getJson('/api/employer/analytics')
        ->assertOk();

    expect($res->json())->toHaveKeys(['jobs', 'applications', 'offers']);
});

test('seeker analytics endpoint is accessible', function () {
    $seekerRes = $this->postJson('/api/auth/register', [
        'name'     => 'Seeker Analytics Test',
        'email'    => 'seeker_analytics_' . uniqid() . '@test.com',
        'password' => 'Password@123',
        'password_confirmation' => 'Password@123',
        'role'    => 'employee',
    ]);
    
    $seekerRes->assertStatus(201);
    $seekerToken = $seekerRes->json('access_token');

    $res = $this->withHeader('Authorization', "Bearer $seekerToken")
        ->getJson('/api/job-seeker/analytics')
        ->assertOk();

    expect($res->json())->toHaveKeys(['applications', 'offers', 'ats_score']);
});
