<?php

// ============================================================
// Tests for GET /api/jobs/{id} — company snippet embedding
// ============================================================

use App\Models\CompanyProfile;
use App\Models\JobPost;
use App\Models\User;

// ── Helpers ──────────────────────────────────────────────────

function makeEmployerWithCompany(array $privateInfo = []): array
{
    $employer = User::factory()->employer()->create();

    $company = CompanyProfile::create([
        'employer_id' => (string) $employer->_id,
        'name'        => 'Acme Corp',
        'slug'        => 'acme-corp',
        'description' => 'We build software.',
        'city'        => 'Damascus',
        'country'     => 'Syria',
        'logo'        => 'https://example.com/logo.png',
        'private_info' => array_merge([
            'expose_to_applicants' => false,
            'address'              => 'Mazzeh Street 12',
            'social_media'         => [
                'linkedin'  => 'https://linkedin.com/company/acme',
                'github'    => null,
                'twitter'   => null,
                'facebook'  => null,
                'instagram' => null,
                'telegram'  => null,
                'behance'   => null,
            ],
        ], $privateInfo),
    ]);

    return [$employer, $company];
}

function makeJobPostWithCompany(User $employer, CompanyProfile $company): JobPost
{
    return JobPost::create([
        'job_id'             => 'JOB-TEST',
        'employer_id'        => (string) $employer->_id,
        'company_profile_id' => (string) $company->_id,
        'company_name'       => $company->name,
        'company_logo'       => $company->logo,
        'title'              => 'Senior Laravel Developer',
        'description'        => 'We are looking for a developer.',
        'vacancies'          => 1,
        'job_type'           => 'full_time',
        'city'               => 'Damascus',
        'communication_method' => 'by_forsa',
        'is_active'          => true,
    ]);
}

afterEach(function () {
    JobPost::where('job_id', 'like', 'JOB-TEST%')->orWhere('job_id', 'JOB-ORPHAN')->delete();
    CompanyProfile::where('slug', 'acme-corp')->orWhere('slug', 'bare-corp')->delete();
});

// ── Company snippet present ───────────────────────────────────

test('get job returns embedded company snippet', function () {
    [$employer, $company] = makeEmployerWithCompany();
    $job = makeJobPostWithCompany($employer, $company);

    $this->getJson("/api/jobs/{$job->_id}")
        ->assertStatus(200)
        ->assertJsonStructure([
            'company' => ['_id', 'slug', 'name', 'logo', 'description', 'city', 'country', 'social_media'],
        ]);

    $employer->delete();
});

test('company snippet contains correct values', function () {
    [$employer, $company] = makeEmployerWithCompany();
    $job = makeJobPostWithCompany($employer, $company);

    $response = $this->getJson("/api/jobs/{$job->_id}")->assertStatus(200);

    $response->assertJsonPath('company.name', 'Acme Corp')
             ->assertJsonPath('company.slug', 'acme-corp')
             ->assertJsonPath('company.description', 'We build software.')
             ->assertJsonPath('company.city', 'Damascus')
             ->assertJsonPath('company.country', 'Syria')
             ->assertJsonPath('company.logo', 'https://example.com/logo.png');

    $employer->delete();
});

test('company snippet includes social media links', function () {
    [$employer, $company] = makeEmployerWithCompany();
    $job = makeJobPostWithCompany($employer, $company);

    $this->getJson("/api/jobs/{$job->_id}")
        ->assertStatus(200)
        ->assertJsonPath('company.social_media.linkedin', 'https://linkedin.com/company/acme');

    $employer->delete();
});

test('company snippet is null-safe when no private_info set', function () {
    $employer = User::factory()->employer()->create();

    $company = CompanyProfile::create([
        'employer_id' => (string) $employer->_id,
        'name'        => 'Bare Corp',
        'slug'        => 'bare-corp',
        'logo'        => null,
        'description' => null,
        'city'        => null,
        'country'     => null,
        // no private_info
    ]);

    $job = makeJobPostWithCompany($employer, $company);

    $this->getJson("/api/jobs/{$job->_id}")
        ->assertStatus(200)
        ->assertJsonPath('company.social_media', null);

    $employer->delete();
});

// ── Job still returns own fields ──────────────────────────────

test('job fields are still present alongside company snippet', function () {
    [$employer, $company] = makeEmployerWithCompany();
    $job = makeJobPostWithCompany($employer, $company);

    $this->getJson("/api/jobs/{$job->_id}")
        ->assertStatus(200)
        ->assertJsonStructure(['job_id', 'title', 'job_type', 'city', 'is_active', 'company']);

    $employer->delete();
});

test('company snippet is absent when company profile does not exist', function () {
    $employer = User::factory()->employer()->create();

    // Job with a non-existent company_profile_id
    $job = JobPost::create([
        'job_id'             => 'JOB-ORPHAN',
        'employer_id'        => (string) $employer->_id,
        'company_profile_id' => '000000000000000000000000',
        'company_name'       => 'Ghost Corp',
        'company_logo'       => null,
        'title'              => 'Ghost Job',
        'description'        => 'Orphaned post.',
        'vacancies'          => 1,
        'job_type'           => 'full_time',
        'city'               => 'Unknown',
        'communication_method' => 'by_forsa',
        'is_active'          => true,
    ]);

    $this->getJson("/api/jobs/{$job->_id}")
        ->assertStatus(200)
        ->assertJsonMissing(['company']);

    $employer->delete();
});

// ── Error cases ───────────────────────────────────────────────

test('returns 404 for non-existent job', function () {
    $this->getJson('/api/jobs/000000000000000000000000')
        ->assertStatus(404)
        ->assertJsonPath('message', 'Job post not found');
});

test('endpoint is public — no auth required', function () {
    [$employer, $company] = makeEmployerWithCompany();
    $job = makeJobPostWithCompany($employer, $company);

    // No withToken() — should still work
    $this->getJson("/api/jobs/{$job->_id}")->assertStatus(200);

    $employer->delete();
});
