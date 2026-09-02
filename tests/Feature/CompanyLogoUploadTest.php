<?php

// =============================================================================
// CompanyLogoUploadTest — Cloudinary-backed logo and cover uploads.
//
// Uses Storage::fake('cloudinary') so no real uploads happen. Covers auth/role
// guards, the missing-profile guard, validation, success, and old-image cleanup.
// =============================================================================

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('cloudinary');
    [$this->employer, $this->token] = userWithToken('employer');
});

// ── Auth / role guards ───────────────────────────────────────────────────

test('uploading a logo requires authentication', function () {
    $this->postJson('/api/employer/company/logo', ['logo' => UploadedFile::fake()->create('logo.jpg', 10, 'image/jpeg')])
        ->assertUnauthorized();
});

test('a seeker cannot upload a logo', function () {
    $this->withToken(tokenFor('employee'))
        ->postJson('/api/employer/company/logo', ['logo' => UploadedFile::fake()->create('logo.jpg', 10, 'image/jpeg')])
        ->assertForbidden();
});

test('uploading a cover requires authentication', function () {
    $this->postJson('/api/employer/company/cover', ['cover_image' => UploadedFile::fake()->create('cover.jpg', 10, 'image/jpeg')])
        ->assertUnauthorized();
});

// ── Missing-profile guard ────────────────────────────────────────────────

test('uploading a logo without a company profile returns 404', function () {
    $this->withToken($this->token)
        ->postJson('/api/employer/company/logo', ['logo' => UploadedFile::fake()->create('logo.jpg', 10, 'image/jpeg')])
        ->assertNotFound()
        ->assertJsonPath('message', 'No company profile found. Create one first.');
});

test('uploading a cover without a company profile returns 404', function () {
    $this->withToken($this->token)
        ->postJson('/api/employer/company/cover', ['cover_image' => UploadedFile::fake()->create('cover.jpg', 10, 'image/jpeg')])
        ->assertNotFound();
});

// ── Validation ───────────────────────────────────────────────────────────

test('the logo field is required', function () {
    createCompanyFor($this->employer);

    $this->withToken($this->token)->postJson('/api/employer/company/logo', [])
        ->assertStatus(422)
        ->assertJsonStructure(['logo']);
});

test('the logo must be an image', function () {
    createCompanyFor($this->employer);

    $this->withToken($this->token)->postJson('/api/employer/company/logo', [
        'logo' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
    ])->assertStatus(422)->assertJsonStructure(['logo']);
});

test('the logo cannot exceed 2 MB', function () {
    createCompanyFor($this->employer);

    $this->withToken($this->token)->postJson('/api/employer/company/logo', [
        'logo' => UploadedFile::fake()->create('logo.jpg', 3000, 'image/jpeg'),
    ])->assertStatus(422)->assertJsonStructure(['logo']);
});

test('the cover cannot exceed 4 MB', function () {
    createCompanyFor($this->employer);

    $this->withToken($this->token)->postJson('/api/employer/company/cover', [
        'cover_image' => UploadedFile::fake()->create('cover.jpg', 5000, 'image/jpeg'),
    ])->assertStatus(422)->assertJsonStructure(['cover_image']);
});

// ── Successful uploads ───────────────────────────────────────────────────

test('an employer can upload a logo and receive its url', function () {
    createCompanyFor($this->employer);

    $response = $this->withToken($this->token)
        ->postJson('/api/employer/company/logo', ['logo' => UploadedFile::fake()->create('logo.png', 10, 'image/jpeg')])
        ->assertOk()
        ->assertJsonStructure(['logo']);

    expect($response->json('logo'))->not->toBeNull();
});

test('an employer can upload a cover image and receive its url', function () {
    createCompanyFor($this->employer);

    $response = $this->withToken($this->token)
        ->postJson('/api/employer/company/cover', ['cover_image' => UploadedFile::fake()->create('cover.webp', 10, 'image/jpeg')])
        ->assertOk()
        ->assertJsonStructure(['cover_image']);

    expect($response->json('cover_image'))->not->toBeNull();
});

test('uploading a logo stores its public_id on the profile', function () {
    $profile = createCompanyFor($this->employer);

    $this->withToken($this->token)
        ->postJson('/api/employer/company/logo', ['logo' => UploadedFile::fake()->create('logo.jpg', 10, 'image/jpeg')])
        ->assertOk();

    expect($profile->fresh()->logo_public_id)->not->toBeNull();
});

// ── Old-image cleanup on replacement ─────────────────────────────────────

test('uploading a new logo deletes the previous one', function () {
    createCompanyFor($this->employer, [
        'logo'           => 'https://res.cloudinary.com/x/image/upload/company-logos/old-logo.jpg',
        'logo_public_id' => 'company-logos/old-logo',
    ]);
    Storage::disk('cloudinary')->put('company-logos/old-logo', '');

    $this->withToken($this->token)
        ->postJson('/api/employer/company/logo', ['logo' => UploadedFile::fake()->create('new-logo.jpg', 10, 'image/jpeg')])
        ->assertOk();

    Storage::disk('cloudinary')->assertMissing('company-logos/old-logo');
});

test('uploading a new cover deletes the previous one', function () {
    createCompanyFor($this->employer, [
        'cover_image'           => 'https://res.cloudinary.com/x/image/upload/company-covers/old-cover.jpg',
        'cover_image_public_id' => 'company-covers/old-cover',
    ]);
    Storage::disk('cloudinary')->put('company-covers/old-cover', '');

    $this->withToken($this->token)
        ->postJson('/api/employer/company/cover', ['cover_image' => UploadedFile::fake()->create('new-cover.png', 10, 'image/jpeg')])
        ->assertOk();

    Storage::disk('cloudinary')->assertMissing('company-covers/old-cover');
});
