<?php

// =============================================================================
// CompanyProfileMyProfileTest
//   GET /api/employer/company        (owner view)
//   PUT /api/employer/company/private (private info merge)
// =============================================================================

use App\Models\CompanyProfile;

/** An employer + token that already owns a fully-populated company profile. */
function employerWithProfile(): array
{
    [$employer, $token] = userWithToken('employer');

    $profile = createCompanyFor($employer, [
        'name'          => 'My Test Corp',
        'description'   => 'A company for testing.',
        'industry'      => 'Technology',
        'company_size'  => '10_to_50',
        'city'          => 'Damascus',
        'country'       => 'Syria',
        'phone_main'    => '0912345678',
        'phone_visible' => true,
        'email'         => 'contact@mytestcorp.com',
    ]);

    return [$employer, $token, $profile];
}

// ── GET /api/employer/company ────────────────────────────────────────────

test('an employer can fetch their own company profile', function () {
    [, $token] = employerWithProfile();

    $this->withToken($token)->getJson('/api/employer/company')
        ->assertOk()
        ->assertJsonPath('name', 'My Test Corp')
        ->assertJsonPath('city', 'Damascus')
        ->assertJsonPath('country', 'Syria');
});

test('the owner view includes every public updatable field', function () {
    [, $token] = employerWithProfile();

    $data = $this->withToken($token)->getJson('/api/employer/company')->assertOk()->json();

    expect($data)->toHaveKeys(['name', 'description', 'industry', 'company_size', 'city', 'country', 'phone_main', 'phone_visible', 'email']);
});

test('the owner view includes private_info and open_positions', function () {
    [, $token] = employerWithProfile();

    $data = $this->withToken($token)->getJson('/api/employer/company')->assertOk()->json();

    expect($data)->toHaveKeys(['private_info', 'open_positions']);
});

test('the owner view returns 404 when no profile exists', function () {
    [, $token] = userWithToken('employer');

    $this->withToken($token)->getJson('/api/employer/company')->assertNotFound();
});

test('an unauthenticated user cannot fetch the owner view', function () {
    $this->getJson('/api/employer/company')->assertUnauthorized();
});

test('a job seeker cannot fetch the owner view', function () {
    $this->withToken(tokenFor('employee'))->getJson('/api/employer/company')->assertForbidden();
});

// ── PUT /api/employer/company/private ────────────────────────────────────

test('an employer can update their private info', function () {
    [, $token, $profile] = employerWithProfile();

    $this->withToken($token)->putJson('/api/employer/company/private', [
        'address'      => 'Mazzeh, Damascus',
        'founded_year' => 2018,
        'website'      => 'https://mytestcorp.com',
    ])->assertOk();

    $private = $profile->fresh()->private_info;
    expect($private['address'])->toBe('Mazzeh, Damascus')
        ->and($private['founded_year'])->toBe(2018)
        ->and($private['website'])->toBe('https://mytestcorp.com');
});

test('private info updates merge rather than replace', function () {
    [, $token, $profile] = employerWithProfile();

    $this->withToken($token)->putJson('/api/employer/company/private', ['address' => 'First Street', 'phone_main' => '0911111111']);
    $this->withToken($token)->putJson('/api/employer/company/private', ['founded_year' => 2020])->assertOk();

    $private = $profile->fresh()->private_info;
    expect($private['address'])->toBe('First Street')
        ->and($private['founded_year'])->toBe(2020);
});

test('private info accepts social media links', function () {
    [, $token, $profile] = employerWithProfile();

    $this->withToken($token)->putJson('/api/employer/company/private', [
        'social_media' => ['linkedin' => 'https://linkedin.com/company/mytestcorp'],
    ])->assertOk();

    expect($profile->fresh()->private_info['social_media']['linkedin'])->toBe('https://linkedin.com/company/mytestcorp');
});

test('private info accepts the expose_to_applicants flag', function () {
    [, $token, $profile] = employerWithProfile();

    $this->withToken($token)->putJson('/api/employer/company/private', ['expose_to_applicants' => true])->assertOk();

    expect($profile->fresh()->private_info['expose_to_applicants'])->toBeTrue();
});

test('private info rejects an out-of-range founded_year', function () {
    [, $token] = employerWithProfile();

    $this->withToken($token)->putJson('/api/employer/company/private', ['founded_year' => 1700])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['founded_year']]);
});

test('private info rejects an invalid website url', function () {
    [, $token] = employerWithProfile();

    $this->withToken($token)->putJson('/api/employer/company/private', ['website' => 'not-a-url'])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['website']]);
});

test('private info rejects an invalid social media url', function () {
    [, $token] = employerWithProfile();

    $this->withToken($token)->putJson('/api/employer/company/private', ['social_media' => ['linkedin' => 'bad-url']])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['social_media.linkedin']]);
});

test('private info update returns 404 when no profile exists', function () {
    [, $token] = userWithToken('employer');

    $this->withToken($token)->putJson('/api/employer/company/private', ['address' => 'Somewhere'])->assertNotFound();
});

test('the private info response includes the private_info block', function () {
    [, $token] = employerWithProfile();

    $this->withToken($token)->putJson('/api/employer/company/private', ['address' => 'Test Address'])
        ->assertOk()
        ->assertJsonStructure(['private_info']);
});

test('an unauthenticated user cannot update private info', function () {
    $this->putJson('/api/employer/company/private', ['address' => 'X'])->assertUnauthorized();
});

test('a job seeker cannot update private info', function () {
    $this->withToken(tokenFor('employee'))
        ->putJson('/api/employer/company/private', ['address' => 'Sneaky'])
        ->assertForbidden();
});
