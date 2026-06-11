<?php

use App\Models\Application;
use App\Models\CompanyProfile;
use App\Models\DirectOffer;
use App\Models\Employer;
use App\Models\JobPost;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Tests\Helpers\TestUserHelper;

uses(TestUserHelper::class);

// Note: Tests use unique data to avoid conflicts instead of truncating collections

test('admin analytics returns comprehensive platform stats', function () {
    $admin = $this->registerAdmin('admin_analytics_' . uniqid() . '@test.com', 'Admin User');

    $seeker1 = $this->registerSeeker('s1_' . uniqid() . '@test.com', 'Seeker 1');
    JobSeekerProfile::create(['user_id' => $seeker1['user_id'], 'ats_score' => 80, 'ai_skills' => ['PHP', 'Laravel']]);

    $seeker2 = $this->registerSeeker('s2_' . uniqid() . '@test.com', 'Seeker 2');
    JobSeekerProfile::create(['user_id' => $seeker2['user_id'], 'ats_score' => 90]);

    $emp1 = $this->registerApprovedEmployer('e1_' . uniqid() . '@test.com', 'Employer 1');
    CompanyProfile::create(['employer_id' => $emp1['user_id'], 'company_name' => 'Company A']);

    $emp2 = $this->registerApprovedEmployer('e2_' . uniqid() . '@test.com', 'Employer 2');
    CompanyProfile::create(['employer_id' => $emp2['user_id'], 'company_name' => 'Company B']);

    Employer::create(['user_id' => $emp1['user_id'], 'status' => 'approved']);
    Employer::create(['user_id' => $emp2['user_id'], 'status' => 'pending']);

    $job1 = JobPost::create([
        'employer_id'  => $emp1['user_id'],
        'title'        => 'PHP Developer',
        'description'  => 'Desc',
        'company_name' => 'Company A',
        'is_active'    => true,
        'roles'        => ['Backend', 'PHP'],
        'tags'         => ['Laravel'],
    ]);

    $job2 = JobPost::create([
        'employer_id'  => $emp1['user_id'],
        'title'        => 'Frontend Developer',
        'description'  => 'Desc',
        'company_name' => 'Company A',
        'is_active'    => false,
        'roles'        => ['Frontend'],
        'tags'         => ['React'],
    ]);

    Application::create(['user_id' => $seeker1['user_id'], 'job_post_id' => (string) $job1->_id, 'status' => 'pending']);
    Application::create(['user_id' => $seeker2['user_id'], 'job_post_id' => (string) $job1->_id, 'status' => 'accepted']);

    DirectOffer::create([
        'employer_id'    => $emp1['user_id'],
        'job_seeker_id'  => $seeker1['user_id'],
        'job_post_id'    => (string) $job1->_id,
        'message'        => 'Offer',
        'status'         => 'pending',
    ]);

    $res = $this->withHeaders($this->authHeader($admin['token']))
        ->getJson('/api/admin/analytics')
        ->assertOk();

    expect($res->json('users.total'))->toBeGreaterThanOrEqual(5);
    expect($res->json('users.by_role.employee'))->toBeGreaterThanOrEqual(2);
    expect($res->json('users.by_role.employer'))->toBeGreaterThanOrEqual(2);
    expect($res->json('users.by_role.admin'))->toBeGreaterThanOrEqual(1);
    expect($res->json('jobs.total_active'))->toBeGreaterThanOrEqual(1);
    expect($res->json('jobs.total_all'))->toBeGreaterThanOrEqual(2);
    expect($res->json('applications.total'))->toBeGreaterThanOrEqual(2);
    expect($res->json('applications.by_status'))->toHaveKey('pending');
    expect($res->json('applications.by_status'))->toHaveKey('accepted');
    expect($res->json('offers.total'))->toBeGreaterThanOrEqual(1);
    expect($res->json('companies.total'))->toBeGreaterThanOrEqual(2);
    expect($res->json('employer_approvals'))->toHaveKeys(['pending', 'approved', 'rejected', 'approval_rate']);
    expect($res->json('top_skills'))->toBeArray();
    expect($res->json('registrations_by_month'))->toBeArray();
    expect($res->json('top_employers'))->toBeArray();
    expect($res->json('avg_ats_score'))->toBeGreaterThan(0);
});

test('employer analytics returns scoped stats for that employer only', function () {
    $empA = $this->registerApprovedEmployer('empA_' . uniqid() . '@test.com', 'Employer A');
    $empAToken = $empARes->json('token');
    $empAId    = $empARes->json('user.id');

    $empBRes = $this->postJson('/api/auth/register', [
        'name'     => 'Employer B',
        'email'    => 'empB@test.com',
        'password' => 'password123',
        'roles'    => ['employer'],
    ]);
    $empBId = $empBRes->json('user.id');


    CompanyProfile::create(['employer_id' => $empA['user_id'], 'company_name' => 'Company A']);

    $empB = $this->registerApprovedEmployer('empB_' . uniqid() . '@test.com', 'Employer B');
    CompanyProfile::create(['employer_id' => $empB['user_id'], 'company_name' => 'Company B']);

    // Submit employer applications
    Employer::create(['user_id' => $empA['user_id'], 'status' => 'approved']);
    Employer::create(['user_id' => $empB['user_id'], 'status' => 'approved']);

    $jobA1Res = $this->withHeaders($this->authHeader($empA['token']))
        ->postJson('/api/employer/jobs', [
            'title'       => 'Job A1',
            'description' => 'Desc',
            'company_name'=> 'Company A',
        ])
        ->assertCreated();
    $jobA1Id = $jobA1Res->json('job.id');

    $this->withHeaders($this->authHeader($empA['token']))
        ->postJson('/api/employer/jobs', [
            'title'       => 'Job A2',
            'description' => 'Desc',
            'company_name'=> 'Company A',
        ])
        ->assertCreated();

    $this->withHeaders($this->authHeader($empA['token']))
        ->postJson("/api/employer/jobs/{$jobA1Id}/deactivate")
        ->assertOk();

    $seeker = $this->registerSeeker('seeker_' . uniqid() . '@test.com', 'Seeker');
    JobSeekerProfile::create(['user_id' => $seeker['user_id'], 'ats_score' => 75, 'ai_skills' => ['PHP', 'Laravel']]);

    $this->withHeaders($this->authHeader($seeker['token']))
        ->postJson('/api/job-seeker/apply', [
            'job_post_id' => $jobA1Id,
        ])
        ->assertCreated();

    $this->withHeaders($this->authHeader($empA['token']))
        ->postJson('/api/employer/offers', [
            'job_seeker_id' => $seeker['user_id'],
            'job_post_id'   => $jobA1Id,
            'message'       => 'Join us',
        ])
        ->assertCreated();

    $res = $this->withHeaders($this->authHeader($empA['token']))
        ->getJson('/api/employer/analytics')
        ->assertOk();

    expect($res->json('jobs.total'))->toBeGreaterThanOrEqual(2);
    expect($res->json('jobs.active'))->toBeGreaterThanOrEqual(1);
    expect($res->json('jobs.inactive'))->toBeGreaterThanOrEqual(1);
    expect($res->json('applications.total'))->toBeGreaterThanOrEqual(1);
    expect($res->json('applications.by_status'))->toHaveKey('pending');
    expect($res->json('applications_per_job'))->toBeArray();
    expect($res->json('offers.total_sent'))->toBeGreaterThanOrEqual(1);
    expect($res->json('offers'))->toHaveKeys(['total_sent', 'accepted', 'declined']);
    expect($res->json('top_applicant_skills'))->toBeArray();
    expect($res->json('avg_applicant_ats_score'))->toBeGreaterThan(0);
    expect($res->json('recent_applications'))->toBeArray();
});

test('seeker analytics returns scoped stats for that seeker', function () {
    $seeker = $this->registerSeeker('seeker_analytics_' . uniqid() . '@test.com', 'Seeker');

    JobSeekerProfile::create([
        'user_id'        => $seeker['user_id'],
        'ats_score'      => 88,
        'ai_analyzed_at' => now(),
        'ai_skills'      => ['PHP', 'JavaScript'],
    ]);

    $emp = $this->registerApprovedEmployer('emp_' . uniqid() . '@test.com', 'Employer');
    CompanyProfile::create(['employer_id' => $emp['user_id'], 'company_name' => 'Test Company']);
    Employer::create(['user_id' => $emp['user_id'], 'status' => 'approved']);

    $jobRes = $this->withHeaders($this->authHeader($emp['token']))
        ->postJson('/api/employer/jobs', [
            'title'       => 'PHP Developer',
            'description' => 'Desc',
            'company_name'=> 'Company',
            'category'    => 'Engineering',
        ])
        ->assertCreated();
    $jobId = $jobRes->json('job.id');

    $this->withHeaders($this->authHeader($seeker['token']))
        ->postJson('/api/job-seeker/apply', [
            'job_post_id' => $jobId,
        ])
        ->assertCreated();

    $offerRes = $this->withHeaders($this->authHeader($emp['token']))
        ->postJson('/api/employer/offers', [
            'job_seeker_id' => $seeker['user_id'],
            'job_post_id'   => $jobId,
            'message'       => 'Offer',
        ])
        ->assertCreated();
    $offerId = $offerRes->json('offer.id');

    $this->withHeaders($this->authHeader($seeker['token']))
        ->postJson("/api/job-seeker/offers/{$offerId}/accept")
        ->assertOk();

    $res = $this->withHeaders($this->authHeader($seeker['token']))
        ->getJson('/api/job-seeker/analytics')
        ->assertOk();

    expect($res->json('applications.total'))->toBeGreaterThanOrEqual(2);
    expect($res->json('applications.by_status'))->toHaveKey('pending');
    expect($res->json('offers.total_received'))->toBeGreaterThanOrEqual(1);
    expect($res->json('offers.accepted'))->toBeGreaterThanOrEqual(1);
    expect($res->json('ats_score.current'))->toBe(88);
    expect($res->json('ats_score.analyzed_at'))->not->toBeNull();
    expect($res->json('matched_jobs_count'))->toBeGreaterThanOrEqual(0);
    expect($res->json('top_applied_categories'))->toBeArray();
    expect($res->json('recent_applications'))->toBeArray();
});

