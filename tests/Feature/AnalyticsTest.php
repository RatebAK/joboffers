<?php

use App\Models\Application;
use App\Models\CompanyProfile;
use App\Models\DirectOffer;
use App\Models\Employer;
use App\Models\JobPost;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    User::truncate();
    JobSeekerProfile::truncate();
    CompanyProfile::truncate();
    Employer::truncate();
    JobPost::truncate();
    Application::truncate();
    DirectOffer::truncate();
});

test('admin analytics returns comprehensive platform stats', function () {
    $admin = User::create([
        'name'     => 'Admin User',
        'email'    => 'admin@test.com',
        'password' => Hash::make('password123'),
        'roles'    => ['admin'],
    ]);
    $adminToken = auth()->login($admin);

    $seeker1 = User::create(['name' => 'Seeker 1', 'email' => 's1@test.com', 'password' => Hash::make('pass'), 'roles' => ['employee']]);
    JobSeekerProfile::create(['user_id' => (string) $seeker1->_id, 'ats_score' => 80, 'ai_skills' => ['PHP', 'Laravel']]);

    $seeker2 = User::create(['name' => 'Seeker 2', 'email' => 's2@test.com', 'password' => Hash::make('pass'), 'roles' => ['employee']]);
    JobSeekerProfile::create(['user_id' => (string) $seeker2->_id, 'ats_score' => 90]);

    $emp1 = User::create(['name' => 'Employer 1', 'email' => 'e1@test.com', 'password' => Hash::make('pass'), 'roles' => ['employer']]);
    CompanyProfile::create(['employer_id' => (string) $emp1->_id, 'company_name' => 'Company A']);

    $emp2 = User::create(['name' => 'Employer 2', 'email' => 'e2@test.com', 'password' => Hash::make('pass'), 'roles' => ['employer']]);
    CompanyProfile::create(['employer_id' => (string) $emp2->_id, 'company_name' => 'Company B']);

    Employer::create(['user_id' => (string) $emp1->_id, 'status' => 'approved']);
    Employer::create(['user_id' => (string) $emp2->_id, 'status' => 'pending']);

    $job1 = JobPost::create([
        'employer_id'  => (string) $emp1->_id,
        'title'        => 'PHP Developer',
        'description'  => 'Desc',
        'company_name' => 'Company A',
        'is_active'    => true,
        'roles'        => ['Backend', 'PHP'],
        'tags'         => ['Laravel'],
    ]);

    $job2 = JobPost::create([
        'employer_id'  => (string) $emp1->_id,
        'title'        => 'Frontend Developer',
        'description'  => 'Desc',
        'company_name' => 'Company A',
        'is_active'    => false,
        'roles'        => ['Frontend'],
        'tags'         => ['React'],
    ]);

    Application::create(['user_id' => (string) $seeker1->_id, 'job_post_id' => (string) $job1->_id, 'status' => 'pending']);
    Application::create(['user_id' => (string) $seeker2->_id, 'job_post_id' => (string) $job1->_id, 'status' => 'accepted']);

    DirectOffer::create([
        'employer_id'    => (string) $emp1->_id,
        'job_seeker_id'  => (string) $seeker1->_id,
        'job_post_id'    => (string) $job1->_id,
        'message'        => 'Offer',
        'status'         => 'pending',
    ]);

    $res = $this->withHeader('Authorization', "Bearer $adminToken")
        ->getJson('/api/admin/analytics')
        ->assertOk();

    expect($res->json('users.total'))->toBe(5);
    expect($res->json('users.by_role.employee'))->toBe(2);
    expect($res->json('users.by_role.employer'))->toBe(2);
    expect($res->json('users.by_role.admin'))->toBe(1);
    expect($res->json('jobs.total_active'))->toBe(1);
    expect($res->json('jobs.total_all'))->toBe(2);
    expect($res->json('applications.total'))->toBe(2);
    expect($res->json('applications.by_status.pending'))->toBe(1);
    expect($res->json('applications.by_status.accepted'))->toBe(1);
    expect($res->json('offers.total'))->toBe(1);
    expect($res->json('companies.total'))->toBe(2);
    expect($res->json('employer_approvals.pending'))->toBe(1);
    expect($res->json('employer_approvals.approved'))->toBe(1);
    expect($res->json('employer_approvals.approval_rate'))->toBe(100.0);
    expect($res->json('top_skills'))->toBeArray();
    expect($res->json('registrations_by_month'))->toBeArray();
    expect(count($res->json('registrations_by_month')))->toBe(12);
    expect($res->json('top_employers'))->toBeArray();
    expect($res->json('avg_ats_score'))->toBe(85.0);
});

test('employer analytics returns scoped stats for that employer only', function () {
    $empARes = $this->postJson('/api/auth/register', [
        'name'     => 'Employer A',
        'email'    => 'empA@test.com',
        'password' => 'password123',
        'roles'    => ['employer'],
    ]);
    $empAToken = $empARes->json('token');
    $empAId    = $empARes->json('user.id');

    $empBRes = $this->postJson('/api/auth/register', [
        'name'     => 'Employer B',
        'email'    => 'empB@test.com',
        'password' => 'password123',
        'roles'    => ['employer'],
    ]);
    $empBId = $empBRes->json('user.id');

    $admin = User::create([
        'name'     => 'Admin User',
        'email'    => 'admin@test.com',
        'password' => Hash::make('password123'),
        'roles'    => ['admin'],
    ]);
    $adminToken = auth()->login($admin);

    $appA = Employer::where('user_id', $empAId)->first();
    $this->withHeader('Authorization', "Bearer $adminToken")
        ->putJson("/api/admin/employers/{$appA->_id}/approve")
        ->assertOk();

    $appB = Employer::where('user_id', $empBId)->first();
    $this->withHeader('Authorization', "Bearer $adminToken")
        ->putJson("/api/admin/employers/{$appB->_id}/approve")
        ->assertOk();

    $jobA1Res = $this->withHeader('Authorization', "Bearer $empAToken")
        ->postJson('/api/jobs', [
            'title'       => 'Job A1',
            'description' => 'Desc',
            'company_name'=> 'Company A',
        ])
        ->assertCreated();
    $jobA1Id = $jobA1Res->json('job.id');

    $jobA2Res = $this->withHeader('Authorization', "Bearer $empAToken")
        ->postJson('/api/jobs', [
            'title'       => 'Job A2',
            'description' => 'Desc',
            'company_name'=> 'Company A',
        ])
        ->assertCreated();

    $this->withHeader('Authorization', "Bearer $empAToken")
        ->putJson("/api/jobs/{$jobA1Id}/deactivate")
        ->assertOk();

    $seekerRes = $this->postJson('/api/auth/register', [
        'name'     => 'Seeker',
        'email'    => 'seeker@test.com',
        'password' => 'password123',
        'roles'    => ['employee'],
    ]);
    $seekerToken = $seekerRes->json('token');
    $seekerId    = $seekerRes->json('user.id');

    JobSeekerProfile::where('user_id', $seekerId)->update(['ats_score' => 75, 'ai_skills' => ['PHP', 'Laravel']]);

    $this->withHeader('Authorization', "Bearer $seekerToken")
        ->postJson("/api/jobs/{$jobA1Id}/apply")
        ->assertCreated();

    $this->withHeader('Authorization', "Bearer $empAToken")
        ->postJson('/api/employer/offers', [
            'job_seeker_id' => $seekerId,
            'job_post_id'   => $jobA1Id,
            'message'       => 'Join us',
        ])
        ->assertCreated();

    $res = $this->withHeader('Authorization', "Bearer $empAToken")
        ->getJson('/api/employer/analytics')
        ->assertOk();

    expect($res->json('jobs.total'))->toBe(2);
    expect($res->json('jobs.active'))->toBe(1);
    expect($res->json('jobs.inactive'))->toBe(1);
    expect($res->json('applications.total'))->toBe(1);
    expect($res->json('applications.by_status.pending'))->toBe(1);
    expect(count($res->json('applications_per_job')))->toBe(2);
    expect($res->json('offers.total_sent'))->toBe(1);
    expect($res->json('offers.accepted'))->toBe(0);
    expect($res->json('offers.declined'))->toBe(0);
    expect($res->json('top_applicant_skills'))->toBeArray();
    expect($res->json('avg_applicant_ats_score'))->toBe(75.0);
    expect(count($res->json('recent_applications')))->toBe(1);
});

test('seeker analytics returns scoped stats for that seeker', function () {
    $seekerRes = $this->postJson('/api/auth/register', [
        'name'     => 'Seeker',
        'email'    => 'seeker@test.com',
        'password' => 'password123',
        'roles'    => ['employee'],
    ]);
    $seekerToken = $seekerRes->json('token');
    $seekerId    = $seekerRes->json('user.id');

    JobSeekerProfile::where('user_id', $seekerId)->update([
        'ats_score'      => 88,
        'ai_analyzed_at' => now(),
        'ai_skills'      => ['PHP', 'JavaScript'],
    ]);

    $empRes = $this->postJson('/api/auth/register', [
        'name'     => 'Employer',
        'email'    => 'emp@test.com',
        'password' => 'password123',
        'roles'    => ['employer'],
    ]);
    $empToken = $empRes->json('token');
    $empId    = $empRes->json('user.id');

    $admin = User::create([
        'name'     => 'Admin User',
        'email'    => 'admin@test.com',
        'password' => Hash::make('password123'),
        'roles'    => ['admin'],
    ]);
    $adminToken = auth()->login($admin);

    $app = Employer::where('user_id', $empId)->first();
    $this->withHeader('Authorization', "Bearer $adminToken")
        ->putJson("/api/admin/employers/{$app->_id}/approve")
        ->assertOk();

    $jobRes = $this->withHeader('Authorization', "Bearer $empToken")
        ->postJson('/api/jobs', [
            'title'       => 'PHP Developer',
            'description' => 'Desc',
            'company_name'=> 'Company',
            'category'    => 'Engineering',
        ])
        ->assertCreated();
    $jobId = $jobRes->json('job.id');

    $this->withHeader('Authorization', "Bearer $seekerToken")
        ->postJson("/api/jobs/{$jobId}/apply")
        ->assertCreated();

    $offerRes = $this->withHeader('Authorization', "Bearer $empToken")
        ->postJson('/api/employer/offers', [
            'job_seeker_id' => $seekerId,
            'job_post_id'   => $jobId,
            'message'       => 'Offer',
        ])
        ->assertCreated();
    $offerId = $offerRes->json('offer.id');

    $this->withHeader('Authorization', "Bearer $seekerToken")
        ->putJson("/api/job-seeker/offers/{$offerId}/accept")
        ->assertOk();

    $res = $this->withHeader('Authorization', "Bearer $seekerToken")
        ->getJson('/api/job-seeker/analytics')
        ->assertOk();

    expect($res->json('applications.total'))->toBe(2);
    expect($res->json('applications.by_status.pending'))->toBe(2);
    expect($res->json('offers.total_received'))->toBe(1);
    expect($res->json('offers.accepted'))->toBe(1);
    expect($res->json('offers.declined'))->toBe(0);
    expect($res->json('ats_score.current'))->toBe(88);
    expect($res->json('ats_score.analyzed_at'))->not->toBeNull();
    expect($res->json('matched_jobs_count'))->toBeGreaterThanOrEqual(0);
    expect($res->json('top_applied_categories'))->toBeArray();
    expect(count($res->json('recent_applications')))->toBe(2);
});
