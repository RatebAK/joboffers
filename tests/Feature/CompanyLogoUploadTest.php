<?php

// ============================================================
// Tests for Cloudinary-backed logo and cover image uploads.
// Covers: auth guards, role guards, missing profile guard,
// validation (type, size), successful upload, and old-image
// deletion on replacement.
// ============================================================

use App\Models\CompanyProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// ── Helpers ───────────────────────────────────────────────────

function logoEmployer(): array
{
    $employer = User::factory()->employer()->create();
    $token    = auth('api')->login($employer);
    return [$employer, $token];
}

function makeProfile(string $employerId, array $extra = []): CompanyProfile
{
    return CompanyProfile::create(array_merge([
        'employer_id' => $employerId,
        'name'        => 'Test Corp',
        'slug'        => 'test-corp-' . uniqid(),
    ], $extra));
}

// ── Auth / Role guards ────────────────────────────────────────

test('logo upload requires authentication', function () {
    Storage::fake('cloudinary');

    $this->postJson('/api/employer/company/logo', [
        'logo' => UploadedFile::fake()->image('logo.jpg'),
    ])->assertStatus(401);
});

test('seeker cannot upload logo', function () {
    Storage::fake('cloudinary');

    $seeker = User::factory()->employee()->create();
    $token  = auth('api')->login($seeker);

    $this->withToken($token)->postJson('/api/employer/company/logo', [
        'logo' => UploadedFile::fake()->image('logo.jpg'),
    ])->assertStatus(403);

    $seeker->delete();
});

test('cover upload requires authentication', function () {
    Storage::fake('cloudinary');

    $this->postJson('/api/employer/company/cover', [
        'cover_image' => UploadedFile::fake()->image('cover.jpg'),
    ])->assertStatus(401);
});

// ── 404 when no profile exists ────────────────────────────────

test('logo upload returns 404 when no company profile exists', function () {
    Storage::fake('cloudinary');

    [, $token] = logoEmployer();

    $this->withToken($token)->postJson('/api/employer/company/logo', [
        'logo' => UploadedFile::fake()->image('logo.jpg'),
    ])->assertStatus(404)->assertJsonPath('message', 'No company profile found. Create one first.');
});

test('cover upload returns 404 when no company profile exists', function () {
    Storage::fake('cloudinary');

    [, $token] = logoEmployer();

    $this->withToken($token)->postJson('/api/employer/company/cover', [
        'cover_image' => UploadedFile::fake()->image('cover.jpg'),
    ])->assertStatus(404);
});

// ── Validation ────────────────────────────────────────────────

test('logo upload requires logo field', function () {
    Storage::fake('cloudinary');

    [$employer, $token] = logoEmployer();
    $profile = makeProfile((string) $employer->_id);

    $this->withToken($token)->postJson('/api/employer/company/logo', [])
         ->assertStatus(422)
         ->assertJsonStructure(['logo']);

    $profile->delete(); $employer->delete();
});

test('logo upload rejects non-image file', function () {
    Storage::fake('cloudinary');

    [$employer, $token] = logoEmployer();
    $profile = makeProfile((string) $employer->_id);

    $this->withToken($token)->postJson('/api/employer/company/logo', [
        'logo' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
    ])->assertStatus(422)->assertJsonStructure(['logo']);

    $profile->delete(); $employer->delete();
});

test('logo upload rejects file over 2 MB', function () {
    Storage::fake('cloudinary');

    [$employer, $token] = logoEmployer();
    $profile = makeProfile((string) $employer->_id);

    $this->withToken($token)->postJson('/api/employer/company/logo', [
        'logo' => UploadedFile::fake()->image('logo.jpg')->size(3000),
    ])->assertStatus(422)->assertJsonStructure(['logo']);

    $profile->delete(); $employer->delete();
});

test('cover upload rejects file over 4 MB', function () {
    Storage::fake('cloudinary');

    [$employer, $token] = logoEmployer();
    $profile = makeProfile((string) $employer->_id);

    $this->withToken($token)->postJson('/api/employer/company/cover', [
        'cover_image' => UploadedFile::fake()->image('cover.jpg')->size(5000),
    ])->assertStatus(422)->assertJsonStructure(['cover_image']);

    $profile->delete(); $employer->delete();
});

// ── Successful uploads ────────────────────────────────────────

test('employer can upload logo and gets url back', function () {
    Storage::fake('cloudinary');

    [$employer, $token] = logoEmployer();
    $profile = makeProfile((string) $employer->_id);

    $response = $this->withToken($token)->postJson('/api/employer/company/logo', [
        'logo' => UploadedFile::fake()->image('logo.png'),
    ]);

    $response->assertStatus(200)->assertJsonStructure(['logo']);
    expect($response->json('logo'))->not->toBeNull();

    $profile->delete(); $employer->delete();
});

test('employer can upload cover image and gets url back', function () {
    Storage::fake('cloudinary');

    [$employer, $token] = logoEmployer();
    $profile = makeProfile((string) $employer->_id);

    $response = $this->withToken($token)->postJson('/api/employer/company/cover', [
        'cover_image' => UploadedFile::fake()->image('cover.webp'),
    ]);

    $response->assertStatus(200)->assertJsonStructure(['cover_image']);
    expect($response->json('cover_image'))->not->toBeNull();

    $profile->delete(); $employer->delete();
});

test('logo upload stores public_id on profile', function () {
    Storage::fake('cloudinary');

    [$employer, $token] = logoEmployer();
    $profile = makeProfile((string) $employer->_id);

    $this->withToken($token)->postJson('/api/employer/company/logo', [
        'logo' => UploadedFile::fake()->image('logo.jpg'),
    ])->assertStatus(200);

    $profile->refresh();
    expect($profile->logo_public_id)->not->toBeNull();

    $profile->delete(); $employer->delete();
});

// ── Old image deletion on replacement ─────────────────────────

test('uploading a new logo deletes the old one from cloudinary', function () {
    Storage::fake('cloudinary');

    [$employer, $token] = logoEmployer();
    $profile = makeProfile((string) $employer->_id, [
        'logo'           => 'https://res.cloudinary.com/dd8vgoh/image/upload/company-logos/old-logo.jpg',
        'logo_public_id' => 'company-logos/old-logo',
    ]);

    // Seed the fake disk so the file "exists"
    Storage::disk('cloudinary')->put('company-logos/old-logo', '');

    $this->withToken($token)->postJson('/api/employer/company/logo', [
        'logo' => UploadedFile::fake()->image('new-logo.jpg'),
    ])->assertStatus(200);

    Storage::disk('cloudinary')->assertMissing('company-logos/old-logo');

    $profile->delete(); $employer->delete();
});

test('uploading a new cover deletes the old one from cloudinary', function () {
    Storage::fake('cloudinary');

    [$employer, $token] = logoEmployer();
    $profile = makeProfile((string) $employer->_id, [
        'cover_image'           => 'https://res.cloudinary.com/dd8vgoh/image/upload/company-covers/old-cover.jpg',
        'cover_image_public_id' => 'company-covers/old-cover',
    ]);

    Storage::disk('cloudinary')->put('company-covers/old-cover', '');

    $this->withToken($token)->postJson('/api/employer/company/cover', [
        'cover_image' => UploadedFile::fake()->image('new-cover.png'),
    ])->assertStatus(200);

    Storage::disk('cloudinary')->assertMissing('company-covers/old-cover');

    $profile->delete(); $employer->delete();
});
