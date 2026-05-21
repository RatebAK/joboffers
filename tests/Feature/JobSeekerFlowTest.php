<?php

// ============================================================
// DO NOT DELETE — Integration tests for the job seeker flow.
// These tests cover the full lifecycle of a job seeker user:
// registration, login, profile management, job search,
// applying to jobs, withdrawing applications, and direct offers.
// ============================================================

use App\Models\Application;
use App\Models\DirectOffer;
use App\Models\JobPost;
use App\Models\JobSeekerProfile;
use App\Models\User;

function makeSeeker(): array
{
    $seeker = User::factory()->employee()->create();
    $token  = auth('api')->login($seeker);
    return [$seeker, $token];
}

function makeEmployerWithJob(): array
{
    $employer = User::factory()->employer()->create();
    $job = JobPost::create([
        'title'        => 'PHP Developer',
        'description'  => 'Build great APIs.',
        'requirements' => 'Laravel experience required.',
        'company_name' => 'FlowCorp',
        'job_type'     => 'full_time',
        'location'     => 'Remote',
        'employer_id'  => (string) $employer->_id,
        'is_active'    => true,
    ]);
    return [$employer, $job];
}

test('job seeker can register with employee role', function () {
    $email = 'new_seeker_' . uniqid() . '@example.com';
    $response = $this->postJson('/api/auth/register', [
        'name'                  => 'New Seeker',
        'email'                 => $email,
        'password'              => 'Password1!',
        'password_confirmation' => 'Password1!',
        'role'                  => 'employee',
    ]);
    $response->assertStatus(201)
             ->assertJsonPath('user.roles.0', 'employee')
             ->assertJsonStructure(['access_token', 'token_type', 'user']);
    User::where('email', $email)->delete();
});

test('registration defaults to employee role when no role given', function () {
    $email = 'default_role_' . uniqid() . '@example.com';
    $response = $this->postJson('/api/auth/register', [
        'name'                  => 'Default Role User',
        'email'                 => $email,
        'password'              => 'Password1!',
        'password_confirmation' => 'Password1!',
    ]);
    $response->assertStatus(201)->assertJsonPath('user.roles.0', 'employee');
    User::where('email', $email)->delete();
});

test('job seeker can login and receive token', function () {
    [$seeker] = makeSeeker();
    $response = $this->postJson('/api/auth/login', [
        'email'    => $seeker->email,
        'password' => 'password',
    ]);
    $response->assertStatus(200)
             ->assertJsonStructure(['access_token', 'token_type', 'expires_in', 'user']);
    $seeker->delete();
});

test('job seeker can view their profile', function () {
    [$seeker, $token] = makeSeeker();
    $this->withToken($token)->getJson('/api/job-seeker/profile')
         ->assertStatus(200)->assertJsonStructure(['profile']);
    JobSeekerProfile::where('user_id', $seeker->_id)->delete();
    $seeker->delete();
});

test('job seeker can update their profile', function () {
    [$seeker, $token] = makeSeeker();
    $response = $this->withToken($token)->putJson('/api/job-seeker/profile', [
        'current_job_title'   => 'Backend Developer',
        'expected_salary'     => 3000,
        'is_actively_seeking' => true,
        'skills' => [['name' => 'PHP'], ['name' => 'Laravel'], ['name' => 'MongoDB']],
    ]);
    $response->assertStatus(200)
             ->assertJsonPath('profile.current_job_title', 'Backend Developer')
             ->assertJsonPath('profile.expected_salary', 3000);
    JobSeekerProfile::where('user_id', $seeker->_id)->delete();
    $seeker->delete();
});

test('profile update rejects invalid data', function () {
    [$seeker, $token] = makeSeeker();
    $this->withToken($token)->putJson('/api/job-seeker/profile', [
        'expected_salary' => 'not-a-number',
        'linkedin_url'    => str_repeat('a', 300),
    ])->assertStatus(422)->assertJsonStructure(['errors']);
    $seeker->delete();
});

test('job seeker can browse active job posts', function () {
    [$seeker, $token] = makeSeeker();
    $this->withToken($token)->getJson('/api/job-seeker/jobs/search')
         ->assertStatus(200)->assertJsonStructure(['jobs']);
    $seeker->delete();
});

test('public job list returns only active posts', function () {
    [$employer, $job] = makeEmployerWithJob();
    $inactive = JobPost::create([
        'title' => 'Inactive Job', 'description' => 'Hidden.',
        'requirements' => 'N/A', 'company_name' => 'Ghost Corp',
        'job_type' => 'contract', 'employer_id' => (string) $employer->_id,
        'is_active' => false,
    ]);
    $response = $this->getJson('/api/jobs')->assertStatus(200);
    $ids = collect($response->json('data'))->pluck('_id')->toArray();
    expect($ids)->not->toContain((string) $inactive->_id);
    $inactive->delete(); $job->delete(); $employer->delete();
});

test('job seeker can filter jobs by keyword', function () {
    [$seeker, $token] = makeSeeker();
    [$employer, $job] = makeEmployerWithJob();
    $response = $this->withToken($token)->getJson('/api/job-seeker/jobs/search?keyword=PHP');
    $response->assertStatus(200);
    foreach ($response->json('jobs.data') as $j) {
        $haystack = strtolower($j['title'] . ' ' . $j['description'] . ' ' . ($j['company_name'] ?? ''));
        expect($haystack)->toContain('php');
    }
    $job->delete(); $employer->delete(); $seeker->delete();
});

test('job seeker can apply to an active job post', function () {
    [$seeker, $token] = makeSeeker();
    [$employer, $job] = makeEmployerWithJob();
    $response = $this->withToken($token)->postJson('/api/job-seeker/apply', [
        'job_post_id'  => (string) $job->_id,
        'cover_letter' => 'I am very interested.',
    ]);
    $response->assertStatus(201)->assertJsonPath('application.status', 'pending');
    Application::where('user_id', $seeker->_id)->delete();
    $job->delete(); $employer->delete(); $seeker->delete();
});

test('job seeker cannot apply twice to same job', function () {
    [$seeker, $token] = makeSeeker();
    [$employer, $job] = makeEmployerWithJob();
    Application::create(['user_id' => $seeker->_id, 'job_post_id' => $job->_id, 'status' => 'pending', 'applied_at' => now()]);
    $this->withToken($token)->postJson('/api/job-seeker/apply', ['job_post_id' => (string) $job->_id])->assertStatus(409);
    Application::where('user_id', $seeker->_id)->delete();
    $job->delete(); $employer->delete(); $seeker->delete();
});

test('job seeker cannot apply to inactive job', function () {
    [$seeker, $token] = makeSeeker();
    [$employer, $job] = makeEmployerWithJob();
    $job->update(['is_active' => false]);
    $this->withToken($token)->postJson('/api/job-seeker/apply', ['job_post_id' => (string) $job->_id])->assertStatus(404);
    $job->delete(); $employer->delete(); $seeker->delete();
});

test('job seeker can list their applications', function () {
    [$seeker, $token] = makeSeeker();
    [$employer, $job] = makeEmployerWithJob();
    Application::create(['user_id' => $seeker->_id, 'job_post_id' => $job->_id, 'status' => 'pending', 'applied_at' => now()]);
    $this->withToken($token)->getJson('/api/job-seeker/applications')
         ->assertStatus(200)->assertJsonStructure(['applications']);
    Application::where('user_id', $seeker->_id)->delete();
    $job->delete(); $employer->delete(); $seeker->delete();
});

test('job seeker can withdraw a pending application', function () {
    [$seeker, $token] = makeSeeker();
    [$employer, $job] = makeEmployerWithJob();
    $app = Application::create(['user_id' => $seeker->_id, 'job_post_id' => $job->_id, 'status' => 'pending', 'applied_at' => now()]);
    $this->withToken($token)->deleteJson("/api/job-seeker/applications/{$app->_id}/withdraw")->assertStatus(200);
    expect(Application::find($app->_id))->toBeNull();
    $job->delete(); $employer->delete(); $seeker->delete();
});

test('job seeker cannot withdraw an accepted application', function () {
    [$seeker, $token] = makeSeeker();
    [$employer, $job] = makeEmployerWithJob();
    $app = Application::create(['user_id' => $seeker->_id, 'job_post_id' => $job->_id, 'status' => 'accepted', 'applied_at' => now()]);
    $this->withToken($token)->deleteJson("/api/job-seeker/applications/{$app->_id}/withdraw")->assertStatus(403);
    $app->delete(); $job->delete(); $employer->delete(); $seeker->delete();
});

test('job seeker can list received direct offers', function () {
    [$seeker, $token] = makeSeeker();
    $this->withToken($token)->getJson('/api/job-seeker/offers')
         ->assertStatus(200)->assertJsonStructure(['offers']);
    $seeker->delete();
});

test('job seeker can decline a direct offer', function () {
    [$seeker, $token] = makeSeeker();
    [$employer, $job] = makeEmployerWithJob();
    $offer = DirectOffer::create(['employer_id' => $employer->_id, 'job_seeker_id' => $seeker->_id, 'job_post_id' => $job->_id, 'message' => 'Great fit.', 'status' => 'pending']);
    $this->withToken($token)->postJson("/api/job-seeker/offers/{$offer->_id}/decline")->assertStatus(200);
    expect(DirectOffer::find($offer->_id)->status)->toBe('declined');
    $offer->delete(); $job->delete(); $employer->delete(); $seeker->delete();
});

test('job seeker can accept a direct offer and application is created', function () {
    [$seeker, $token] = makeSeeker();
    [$employer, $job] = makeEmployerWithJob();
    $offer = DirectOffer::create(['employer_id' => $employer->_id, 'job_seeker_id' => $seeker->_id, 'job_post_id' => $job->_id, 'message' => 'Join us!', 'status' => 'pending']);
    $this->withToken($token)->postJson("/api/job-seeker/offers/{$offer->_id}/accept")->assertStatus(200);
    expect(DirectOffer::find($offer->_id)->status)->toBe('accepted');
    $application = Application::where('user_id', $seeker->_id)->where('job_post_id', $job->_id)->first();
    expect($application)->not->toBeNull();
    expect($application->status)->toBe('pending');
    Application::where('user_id', $seeker->_id)->delete();
    $offer->delete(); $job->delete(); $employer->delete(); $seeker->delete();
});

test('employer cannot access job seeker endpoints', function () {
    $employer = User::factory()->employer()->create();
    $token = auth('api')->login($employer);
    $this->withToken($token)->getJson('/api/job-seeker/profile')->assertStatus(403);
    $employer->delete();
});
