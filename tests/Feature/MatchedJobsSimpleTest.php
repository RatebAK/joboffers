<?php

test('matched jobs endpoint is accessible for job seekers', function () {
    $seekerRes = $this->postJson('/api/auth/register', [
        'name'     => 'Seeker Matched Test',
        'email'    => 'seeker_matched_' . uniqid() . '@test.com',
        'password' => 'Password@123',
        'password_confirmation' => 'Password@123',
        'role'    => 'employee',
    ]);
    $seekerToken = $seekerRes->json('access_token');

    $res = $this->withHeader('Authorization', "Bearer $seekerToken")
        ->getJson('/api/job-seeker/matched-jobs')
        ->assertOk();

    expect($res->json())->toHaveKeys(['data', 'current_page', 'per_page', 'total']);
});

test('matched jobs returns paginated results', function () {
    $seekerRes = $this->postJson('/api/auth/register', [
        'name'     => 'Seeker Pagination Test',
        'email'    => 'seeker_page_' . uniqid() . '@test.com',
        'password' => 'Password@123',
        'password_confirmation' => 'Password@123',
        'role'    => 'employee',
    ]);
    $seekerToken = $seekerRes->json('access_token');

    $res = $this->withHeader('Authorization', "Bearer $seekerToken")
        ->getJson('/api/job-seeker/matched-jobs?per_page=5')
        ->assertOk();

    expect($res->json('per_page'))->toBe(10); // MatchedJobsController hardcodes perPage to 10
    expect($res->json('data'))->toBeArray();
});
