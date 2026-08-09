<?php

// ============================================================
// Tests for GET /api/employer/company (myProfile)
// and PUT /api/employer/company/private (updatePrivate)
// ============================================================

use App\Models\CompanyProfile;
use App\Models\User;

// ── Helpers ──────────────────────────────────────────────────

function myProfileEmployer(): array
{
    $employer = User::factory()->employer()->create();
    $token    = auth('api')->login($employer);
    return [$employer, $token];
}

function myProfileWithCompany(): array
{
    [$employer, $token] = myProfileEmployer();

    $profile = CompanyProfile::create([
        'employer_id'  => (string) $employer->_id,
        'name'         => 'My Test Corp',
        'slug'         => 'my-test-corp-' . uniqid(),
        'description'  => 'A company for testing.',
        'industry'     => 'Technology',
        'company_size' => '10_to_50',
        'city'         => 'Damascus',
        'country'      => 'Syria',
        'phone_main'   => '0912345678',
        'phone_visible'=> true,
        'email'        => 'contact@mytestcorp.com',
        'rating'       => 0,
        'review_count' => 0,
        'would_recommend' => 0,
        'ceo_performance' => 0,
        'category_ratings' => [
            'compensation' => 0, 'culture' => 0,
            'work_life' => 0, 'diversity' => 0, 'management' => 0,
        ],
        'reviews' => [],
    ]);

    return [$employer, $token, $profile];
}

// ── GET /api/employer/company ────────────────────────────────

test('employer can fetch own company profile', function () {
    [$employer, $token, $profile] = myProfileWithCompany();

    $this->withToken($token)->getJson('/api/employer/company')
         ->assertStatus(200)
         ->assertJsonPath('name', 'My Test Corp')
         ->assertJsonPath('city', 'Damascus')
         ->assertJsonPath('country', 'Syria');

    $profile->delete(); $employer->delete();
});

test('my profile response includes all public updatable fields', function () {
    [$employer, $token, $profile] = myProfileWithCompany();

    $response = $this->withToken($token)->getJson('/api/employer/company')->assertStatus(200);

    $data = $response->json();
    foreach (['name', 'description', 'industry', 'company_size', 'city', 'country', 'phone_main', 'phone_visible', 'email'] as $field) {
        expect($data)->toHaveKey($field);
    }

    $profile->delete(); $employer->delete();
});

test('my profile response includes private_info key', function () {
    [$employer, $token, $profile] = myProfileWithCompany();

    $response = $this->withToken($token)->getJson('/api/employer/company')->assertStatus(200);
    expect($response->json())->toHaveKey('private_info');

    $profile->delete(); $employer->delete();
});

test('my profile response includes open_positions', function () {
    [$employer, $token, $profile] = myProfileWithCompany();

    $response = $this->withToken($token)->getJson('/api/employer/company')->assertStatus(200);
    expect($response->json())->toHaveKey('open_positions');

    $profile->delete(); $employer->delete();
});

test('my profile returns 404 when no company profile exists', function () {
    [$employer, $token] = myProfileEmployer();

    $this->withToken($token)->getJson('/api/employer/company')->assertStatus(404);

    $employer->delete();
});

test('unauthenticated user cannot fetch employer company profile', function () {
    $this->getJson('/api/employer/company')->assertStatus(401);
});

test('job seeker cannot fetch employer company profile endpoint', function () {
    $seeker = User::factory()->employee()->create();
    $token  = auth('api')->login($seeker);

    $this->withToken($token)->getJson('/api/employer/company')->assertStatus(403);

    $seeker->delete();
});

// ── PUT /api/employer/company/private ────────────────────────

test('employer can update private info', function () {
    [$employer, $token, $profile] = myProfileWithCompany();

    $this->withToken($token)->putJson('/api/employer/company/private', [
        'address'      => 'Mazzeh, Damascus',
        'founded_year' => 2018,
        'website'      => 'https://mytestcorp.com',
    ])->assertStatus(200);

    $profile->refresh();
    expect($profile->private_info['address'])->toBe('Mazzeh, Damascus');
    expect($profile->private_info['founded_year'])->toBe(2018);
    expect($profile->private_info['website'])->toBe('https://mytestcorp.com');

    $profile->delete(); $employer->delete();
});

test('private info update is a partial merge not a full replace', function () {
    [$employer, $token, $profile] = myProfileWithCompany();

    $this->withToken($token)->putJson('/api/employer/company/private', [
        'address'    => 'First Street',
        'phone_main' => '0911111111',
    ]);

    $this->withToken($token)->putJson('/api/employer/company/private', [
        'founded_year' => 2020,
    ])->assertStatus(200);

    $profile->refresh();
    // First call's data should still be there
    expect($profile->private_info['address'])->toBe('First Street');
    expect($profile->private_info['founded_year'])->toBe(2020);

    $profile->delete(); $employer->delete();
});

test('private info update accepts social media links', function () {
    [$employer, $token, $profile] = myProfileWithCompany();

    $this->withToken($token)->putJson('/api/employer/company/private', [
        'social_media' => [
            'linkedin' => 'https://linkedin.com/company/mytestcorp',
            'github'   => 'https://github.com/mytestcorp',
        ],
    ])->assertStatus(200);

    $profile->refresh();
    expect($profile->private_info['social_media']['linkedin'])->toBe('https://linkedin.com/company/mytestcorp');

    $profile->delete(); $employer->delete();
});

test('private info update accepts expose_to_applicants flag', function () {
    [$employer, $token, $profile] = myProfileWithCompany();

    $this->withToken($token)->putJson('/api/employer/company/private', [
        'expose_to_applicants' => true,
    ])->assertStatus(200);

    $profile->refresh();
    expect($profile->private_info['expose_to_applicants'])->toBeTrue();

    $profile->delete(); $employer->delete();
});

test('private info update rejects invalid founded_year', function () {
    [$employer, $token, $profile] = myProfileWithCompany();

    $this->withToken($token)->putJson('/api/employer/company/private', [
        'founded_year' => 1700,
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['founded_year']]);

    $profile->delete(); $employer->delete();
});

test('private info update rejects invalid website url', function () {
    [$employer, $token, $profile] = myProfileWithCompany();

    $this->withToken($token)->putJson('/api/employer/company/private', [
        'website' => 'not-a-url',
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['website']]);

    $profile->delete(); $employer->delete();
});

test('private info update rejects invalid social media url', function () {
    [$employer, $token, $profile] = myProfileWithCompany();

    $this->withToken($token)->putJson('/api/employer/company/private', [
        'social_media' => ['linkedin' => 'bad-url'],
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['social_media.linkedin']]);

    $profile->delete(); $employer->delete();
});

test('private info update returns 404 when no company profile exists', function () {
    [$employer, $token] = myProfileEmployer();

    $this->withToken($token)->putJson('/api/employer/company/private', [
        'address' => 'Somewhere',
    ])->assertStatus(404);

    $employer->delete();
});

test('private info response includes private_info block', function () {
    [$employer, $token, $profile] = myProfileWithCompany();

    $response = $this->withToken($token)->putJson('/api/employer/company/private', [
        'address' => 'Test Address',
    ])->assertStatus(200);

    expect($response->json())->toHaveKey('private_info');

    $profile->delete(); $employer->delete();
});

test('unauthenticated user cannot update private info', function () {
    $this->putJson('/api/employer/company/private', ['address' => 'X'])->assertStatus(401);
});

test('job seeker cannot update private info', function () {
    $seeker = User::factory()->employee()->create();
    $token  = auth('api')->login($seeker);

    $this->withToken($token)->putJson('/api/employer/company/private', [
        'address' => 'Sneaky',
    ])->assertStatus(403);

    $seeker->delete();
});
