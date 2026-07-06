<?php

// ============================================================
// DO NOT DELETE — Integration tests for the employer flow.
// These tests cover the full lifecycle of an employer user:
// registration, login, job post CRUD, searching job seekers,
// sending direct offers, and managing applications.
// ============================================================

use App\Models\Application;
use App\Models\DirectOffer;
use App\Models\JobPost;
use App\Models\JobSeekerProfile;
use App\Models\User;

function makeEmployer(): array
{
    $employer = User::factory()->employer()->create();
    $token    = auth('api')->login($employer);
    return [$employer, $token];
}

function makeSeekerWithProfile(): array
{
    $seeker = User::factory()->employee()->create();
    $profile = JobSeekerProfile::create([
        'user_id'             => $seeker->_id,
        'is_actively_seeking' => true,
        'current_job_title'   => 'Frontend Developer',
        'ai_skills'           => ['Vue.js', 'JavaScript', 'CSS'],
        'ats_score'           => 75,
        'ai_location'         => 'Beirut, Lebanon',
        'ai_summary'          => 'Experienced frontend developer.',
    ]);
    return [$seeker, $profile];
}

function makeJobPost(string $employerId): JobPost
{
    return JobPost::create([
        'title'        => 'Vue.js Developer',
        'description'  => 'Build beautiful UIs.',
        'company_name' => 'Test Employer Co',
        'job_type'     => 'full_time',
        'city'         => 'Beirut',
        'vacancies'    => 1,
        'communication_method' => 'by_forsa',
        'employer_id'  => $employerId,
        'is_active'    => true,
    ]);
}

/** makeEmployer + ensure company profile exists for job creation. */
function makeEmployerWithCompany(): array
{
    [$employer, $token] = makeEmployer();
    \App\Models\CompanyProfile::updateOrCreate(
        ['employer_id' => (string) $employer->_id],
        ['name' => 'Test Employer Co', 'slug' => 'test-employer-' . uniqid()]
    );
    return [$employer, $token];
}

test('employer can register with employer role', function () {
    $email = 'new_employer_' . uniqid() . '@example.com';
    $response = $this->postJson('/api/auth/register', [
        'name'                  => 'New Employer',
        'email'                 => $email,
        'password'              => 'Password1!',
        'password_confirmation' => 'Password1!',
        'role'                  => 'employer',
    ]);
    $response->assertStatus(201)->assertJsonPath('user.roles.0', 'employer');
    User::where('email', $email)->delete();
});

test('employer can create a job post', function () {
    [$employer, $token] = makeEmployerWithCompany();
    $response = $this->withToken($token)->postJson('/api/employer/jobs', [
        'title'       => 'React Developer',
        'description' => 'Build React apps.',
        'vacancies'   => 1,
        'city'        => 'Beirut',
        'job_type'    => 'contract',
        'communication_method' => 'by_forsa',
    ]);
    $response->assertStatus(201)->assertJsonPath('is_active', true)->assertJsonPath('title', 'React Developer');
    JobPost::where('employer_id', (string) $employer->_id)->delete();
    \App\Models\CompanyProfile::where('employer_id', (string) $employer->_id)->delete();
    $employer->delete();
});

test('job post creation requires mandatory fields', function () {
    [$employer, $token] = makeEmployerWithCompany();
    $this->withToken($token)->postJson('/api/employer/jobs', ['title' => 'Missing Fields'])->assertStatus(422);
    \App\Models\CompanyProfile::where('employer_id', (string) $employer->_id)->delete();
    $employer->delete();
});

test('employer can update their own job post', function () {
    [$employer, $token] = makeEmployer();
    $job = makeJobPost((string) $employer->_id);
    $this->withToken($token)->putJson("/api/employer/jobs/{$job->_id}", ['title' => 'Senior Vue.js Developer'])
         ->assertStatus(200)->assertJsonPath('title', 'Senior Vue.js Developer');
    $job->delete(); $employer->delete();
});

test('employer cannot update another employers job post', function () {
    [$employer, $token] = makeEmployer();
    [$other, $otherToken] = makeEmployer();
    $job = makeJobPost((string) $employer->_id);
    $this->withToken($otherToken)->putJson("/api/employer/jobs/{$job->_id}", ['title' => 'Hijacked'])->assertStatus(403);
    $job->delete(); $employer->delete(); $other->delete();
});

test('employer can deactivate a job post', function () {
    [$employer, $token] = makeEmployer();
    $job = makeJobPost((string) $employer->_id);
    $this->withToken($token)->postJson("/api/employer/jobs/{$job->_id}/deactivate")->assertStatus(200);
    expect((bool) JobPost::find($job->_id)->is_active)->toBeFalse();
    $job->delete(); $employer->delete();
});

test('deactivated job post does not appear in public search', function () {
    [$employer, $token] = makeEmployer();
    $job = makeJobPost((string) $employer->_id);
    $job->update(['is_active' => false]);
    $response = $this->getJson('/api/jobs')->assertStatus(200);
    $ids = collect($response->json('data'))->pluck('_id')->toArray();
    expect($ids)->not->toContain((string) $job->_id);
    $job->delete(); $employer->delete();
});

test('employer can list their own job posts with application counts', function () {
    [$employer, $token] = makeEmployer();
    makeJobPost((string) $employer->_id);
    $response = $this->withToken($token)->getJson('/api/employer/jobs')->assertStatus(200);
    foreach ($response->json() as $post) {
        expect($post)->toHaveKey('application_count');
    }
    JobPost::where('employer_id', (string) $employer->_id)->delete();
    $employer->delete();
});

test('employer can delete their own job post', function () {
    [$employer, $token] = makeEmployer();
    $job = makeJobPost((string) $employer->_id);
    $this->withToken($token)->deleteJson("/api/employer/jobs/{$job->_id}")->assertStatus(200);
    expect(JobPost::find($job->_id))->toBeNull();
    $employer->delete();
});

test('employer can search actively seeking job seekers', function () {
    [$employer, $token] = makeEmployer();
    [$seeker, $profile] = makeSeekerWithProfile();
    $this->withToken($token)->getJson('/api/employer/seekers')
         ->assertStatus(200)->assertJsonStructure(['seekers']);
    $profile->delete(); $seeker->delete(); $employer->delete();
});

test('employer can filter seekers by skills', function () {
    [$employer, $token] = makeEmployer();
    [$seeker, $profile] = makeSeekerWithProfile();
    $response = $this->withToken($token)->getJson('/api/employer/seekers?skills=Vue.js')->assertStatus(200);
    foreach ($response->json('seekers.data') as $s) {
        $skills = array_map('strtolower', $s['ai_skills'] ?? []);
        expect($skills)->toContain('vue.js');
    }
    $profile->delete(); $seeker->delete(); $employer->delete();
});

test('employer can filter seekers by min ats score', function () {
    [$employer, $token] = makeEmployer();
    [$seeker, $profile] = makeSeekerWithProfile();
    $response = $this->withToken($token)->getJson('/api/employer/seekers?min_ats_score=70')->assertStatus(200);
    foreach ($response->json('seekers.data') as $s) {
        expect($s['ats_score'])->toBeGreaterThanOrEqual(70);
    }
    $profile->delete(); $seeker->delete(); $employer->delete();
});

test('employer can view a specific seeker public profile', function () {
    [$employer, $token] = makeEmployer();
    [$seeker, $profile] = makeSeekerWithProfile();
    $response = $this->withToken($token)->getJson("/api/employer/seekers/{$seeker->_id}")
         ->assertStatus(200)->assertJsonStructure(['seeker' => ['user_id', 'name', 'profile']]);
    $profileData = $response->json('seeker.profile');
    expect($profileData)->not->toHaveKey('ai_email');
    expect($profileData)->not->toHaveKey('ai_phone');
    $profile->delete(); $seeker->delete(); $employer->delete();
});

test('employer can send a direct offer to a job seeker', function () {
    [$employer, $token] = makeEmployer();
    [$seeker, $profile] = makeSeekerWithProfile();
    $job = makeJobPost((string) $employer->_id);
    $response = $this->withToken($token)->postJson('/api/employer/offers', [
        'job_seeker_id' => (string) $seeker->_id,
        'job_post_id'   => (string) $job->_id,
        'message'       => 'We think you are a great fit.',
    ]);
    $response->assertStatus(201)->assertJsonPath('offer.status', 'pending');
    DirectOffer::where('employer_id', $employer->_id)->delete();
    $job->delete(); $profile->delete(); $seeker->delete(); $employer->delete();
});

test('employer cannot send duplicate direct offer', function () {
    [$employer, $token] = makeEmployer();
    [$seeker, $profile] = makeSeekerWithProfile();
    $job = makeJobPost((string) $employer->_id);
    DirectOffer::create(['employer_id' => $employer->_id, 'job_seeker_id' => $seeker->_id, 'job_post_id' => $job->_id, 'message' => 'First.', 'status' => 'pending']);
    $this->withToken($token)->postJson('/api/employer/offers', [
        'job_seeker_id' => (string) $seeker->_id, 'job_post_id' => (string) $job->_id, 'message' => 'Second.',
    ])->assertStatus(409);
    DirectOffer::where('employer_id', $employer->_id)->delete();
    $job->delete(); $profile->delete(); $seeker->delete(); $employer->delete();
});

test('employer cannot send offer for another employers job post', function () {
    [$employer, $token] = makeEmployer();
    [$seeker, $profile] = makeSeekerWithProfile();
    $otherJob = makeJobPost('someone_else_id');
    $this->withToken($token)->postJson('/api/employer/offers', [
        'job_seeker_id' => (string) $seeker->_id, 'job_post_id' => (string) $otherJob->_id, 'message' => 'Sneaky.',
    ])->assertStatus(403);
    $otherJob->delete(); $profile->delete(); $seeker->delete(); $employer->delete();
});

test('employer can list sent direct offers', function () {
    [$employer, $token] = makeEmployer();
    $this->withToken($token)->getJson('/api/employer/offers')
         ->assertStatus(200)->assertJsonStructure(['offers']);
    $employer->delete();
});

test('employer can list applications for their job post', function () {
    [$employer, $token] = makeEmployer();
    [$seeker, $profile] = makeSeekerWithProfile();
    $job = makeJobPost((string) $employer->_id);
    Application::create(['user_id' => $seeker->_id, 'job_post_id' => $job->_id, 'status' => 'pending', 'applied_at' => now()]);
    $this->withToken($token)->getJson("/api/employer/jobs/{$job->_id}/applications")
         ->assertStatus(200)->assertJsonStructure(['applications']);
    Application::where('user_id', $seeker->_id)->delete();
    $job->delete(); $profile->delete(); $seeker->delete(); $employer->delete();
});

test('employer can update application status to reviewed with feedback', function () {
    [$employer, $token] = makeEmployer();
    [$seeker, $profile] = makeSeekerWithProfile();
    $job = makeJobPost((string) $employer->_id);
    $app = Application::create(['user_id' => $seeker->_id, 'job_post_id' => $job->_id, 'status' => 'pending', 'applied_at' => now()]);
    $this->withToken($token)->putJson("/api/employer/applications/{$app->_id}/status", [
        'status' => 'reviewed', 'feedback' => 'Strong candidate.',
    ])->assertStatus(200);
    $updated = Application::find($app->_id);
    expect($updated->status)->toBe('reviewed');
    expect($updated->feedback)->toBe('Strong candidate.');
    $app->delete(); $job->delete(); $profile->delete(); $seeker->delete(); $employer->delete();
});

test('employer cannot set invalid application status', function () {
    [$employer, $token] = makeEmployer();
    [$seeker, $profile] = makeSeekerWithProfile();
    $job = makeJobPost((string) $employer->_id);
    $app = Application::create(['user_id' => $seeker->_id, 'job_post_id' => $job->_id, 'status' => 'pending', 'applied_at' => now()]);
    $this->withToken($token)->putJson("/api/employer/applications/{$app->_id}/status", ['status' => 'hired'])->assertStatus(422);
    $app->delete(); $job->delete(); $profile->delete(); $seeker->delete(); $employer->delete();
});

test('employer cannot manage applications for another employers post', function () {
    [$employer, $token] = makeEmployer();
    [$other, $otherToken] = makeEmployer();
    [$seeker, $profile] = makeSeekerWithProfile();
    $job = makeJobPost((string) $employer->_id);
    $app = Application::create(['user_id' => $seeker->_id, 'job_post_id' => $job->_id, 'status' => 'pending', 'applied_at' => now()]);
    $this->withToken($otherToken)->putJson("/api/employer/applications/{$app->_id}/status", ['status' => 'rejected'])->assertStatus(403);
    $app->delete(); $job->delete(); $profile->delete(); $seeker->delete(); $employer->delete(); $other->delete();
});

test('job seeker cannot access employer endpoints', function () {
    $seeker = User::factory()->employee()->create();
    $token  = auth('api')->login($seeker);
    $this->withToken($token)->getJson('/api/employer/jobs')->assertStatus(403);
    $seeker->delete();
});
