<?php

// Covers GET /api/jobs/{id} — embedding of the company snippet on a job post,
// its null-safety, and the not-found / public-access behaviour.

use App\Models\CompanyProfile;
use App\Models\JobPost;

// A company with private_info and a job linked to it via company_profile_id.
// Kept local because the company_profile_id linkage isn't covered by the shared
// helpers.
function jobLinkedToCompany(CompanyProfile $company, array $jobOverrides = []): JobPost
{
    return createJob(
        // employer is implied by the company; reuse its employer_id
        \App\Models\User::find($company->employer_id),
        array_merge([
            'company_profile_id' => (string) $company->_id,
            'company_name'       => $company->name,
            'company_logo'       => $company->logo,
            'title'              => 'Senior Laravel Developer',
        ], $jobOverrides),
    );
}

beforeEach(function () {
    $this->employer = createUser('employer');
    $this->company  = createCompanyFor($this->employer, [
        'name'        => 'Acme Corp',
        'slug'        => 'acme-corp',
        'description' => 'We build software.',
        'city'        => 'Damascus',
        'country'     => 'Syria',
        'logo'        => 'https://example.com/logo.png',
        'private_info' => [
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
        ],
    ]);
});

// ── Company snippet present ────────────────────────────────────────

test('get job returns embedded company snippet', function () {
    $job = jobLinkedToCompany($this->company);

    $this->getJson("/api/jobs/{$job->_id}")
        ->assertOk()
        ->assertJsonStructure([
            'company' => ['_id', 'slug', 'name', 'logo', 'description', 'city', 'country', 'social_media'],
        ]);
});

test('company snippet contains correct values', function () {
    $job = jobLinkedToCompany($this->company);

    $this->getJson("/api/jobs/{$job->_id}")
        ->assertOk()
        ->assertJsonPath('company.name', 'Acme Corp')
        ->assertJsonPath('company.slug', 'acme-corp')
        ->assertJsonPath('company.description', 'We build software.')
        ->assertJsonPath('company.city', 'Damascus')
        ->assertJsonPath('company.country', 'Syria')
        ->assertJsonPath('company.logo', 'https://example.com/logo.png');
});

test('company snippet includes social media links', function () {
    $job = jobLinkedToCompany($this->company);

    $this->getJson("/api/jobs/{$job->_id}")
        ->assertOk()
        ->assertJsonPath('company.social_media.linkedin', 'https://linkedin.com/company/acme');
});

test('company snippet is null-safe when no private_info set', function () {
    $employer = createUser('employer');
    $company  = createCompanyFor($employer, [
        'name'        => 'Bare Corp',
        'slug'        => 'bare-corp',
        'logo'        => null,
        'description' => null,
        'city'        => null,
        'country'     => null,
    ]);

    $job = jobLinkedToCompany($company);

    $this->getJson("/api/jobs/{$job->_id}")
        ->assertOk()
        ->assertJsonPath('company.social_media', null);
});

// ── Job still returns own fields ───────────────────────────────────

test('job fields are still present alongside company snippet', function () {
    $job = jobLinkedToCompany($this->company);

    $this->getJson("/api/jobs/{$job->_id}")
        ->assertOk()
        ->assertJsonStructure(['title', 'job_type', 'city', 'is_active', 'company']);
});

test('company snippet is absent when company profile does not exist', function () {
    $job = createJob($this->employer, [
        'company_profile_id' => '000000000000000000000000',
        'company_name'       => 'Ghost Corp',
        'company_logo'       => null,
        'title'              => 'Ghost Job',
    ]);

    $this->getJson("/api/jobs/{$job->_id}")
        ->assertOk()
        ->assertJsonMissing(['company']);
});

// ── Error cases ────────────────────────────────────────────────────

test('returns 404 for non-existent job', function () {
    $this->getJson('/api/jobs/000000000000000000000000')
        ->assertNotFound()
        ->assertJsonPath('message', 'Job post not found');
});

test('endpoint is public — no auth required', function () {
    $job = jobLinkedToCompany($this->company);

    $this->getJson("/api/jobs/{$job->_id}")->assertOk();
});
