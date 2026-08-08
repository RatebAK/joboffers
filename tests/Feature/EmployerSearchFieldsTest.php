<?php

// Tests for the two field additions:
// 1. GET /api/employer/seekers/{userId} — personal fields, image, and timestamps
// 2. GET /api/job-seeker/profile        — image present in own profile response

use App\Models\JobSeekerProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// ── Helpers ───────────────────────────────────────────────────

function fieldsTestEmployer(): array
{
    $employer = User::factory()->employer()->create();
    $token    = auth('api')->login($employer);
    return [$employer, $token];
}

function fieldsTestSeeker(array $overrides = []): array
{
    $seeker  = User::factory()->employee()->create();
    $profile = JobSeekerProfile::create(array_merge([
        'user_id'             => $seeker->_id,
        'is_actively_seeking' => true,
        'first_name'          => 'Jane',
        'last_name'           => 'Smith',
        'full_name'           => 'Jane Smith',
        'image'               => 'https://res.cloudinary.com/demo/image/upload/photo.jpg',
        'phone'               => '+961 70 123456',
        'date_of_birth'       => '1995-06-15',
        'gender'              => 'female',
        'marital_status'      => 'single',
        'nationality'         => 'Lebanese',
        'address'             => '123 Main St',
        'location'            => 'Beirut, Lebanon',
        'city'                => 'Beirut',
        'current_job_title'   => 'Frontend Developer',
        'ats_score'           => 80,
    ], $overrides));
    return [$seeker, $profile];
}

// ── showJobSeeker: personal fields returned ───────────────────

test('showJobSeeker returns id and user_id', function () {
    [$employer, $token] = fieldsTestEmployer();
    [$seeker, $profile] = fieldsTestSeeker();

    $res = $this->withToken($token)
                ->getJson("/api/employer/seekers/{$seeker->_id}")
                ->assertStatus(200);

    expect($res->json('id'))->toBe((string) $profile->_id);
    expect($res->json('user_id'))->toBe((string) $seeker->_id);

    $profile->delete(); $seeker->delete(); $employer->delete();
});

test('showJobSeeker returns name and email from user model', function () {
    [$employer, $token] = fieldsTestEmployer();
    [$seeker, $profile] = fieldsTestSeeker();

    $res = $this->withToken($token)
                ->getJson("/api/employer/seekers/{$seeker->_id}")
                ->assertStatus(200);

    expect($res->json('name'))->toBe($seeker->name);
    expect($res->json('email'))->toBe($seeker->email);

    $profile->delete(); $seeker->delete(); $employer->delete();
});

test('showJobSeeker returns personal profile fields', function () {
    [$employer, $token] = fieldsTestEmployer();
    [$seeker, $profile] = fieldsTestSeeker();

    $res = $this->withToken($token)
                ->getJson("/api/employer/seekers/{$seeker->_id}")
                ->assertStatus(200);

    expect($res->json('first_name'))->toBe('Jane');
    expect($res->json('last_name'))->toBe('Smith');
    expect($res->json('full_name'))->toBe('Jane Smith');
    expect($res->json('phone'))->toBe('+961 70 123456');
    expect($res->json('date_of_birth'))->toBe('1995-06-15');
    expect($res->json('gender'))->toBe('female');
    expect($res->json('marital_status'))->toBe('single');
    expect($res->json('nationality'))->toBe('Lebanese');
    expect($res->json('address'))->toBe('123 Main St');
    expect($res->json('location'))->toBe('Beirut, Lebanon');
    expect($res->json('city'))->toBe('Beirut');

    $profile->delete(); $seeker->delete(); $employer->delete();
});

test('showJobSeeker returns image field', function () {
    [$employer, $token] = fieldsTestEmployer();
    [$seeker, $profile] = fieldsTestSeeker();

    $res = $this->withToken($token)
                ->getJson("/api/employer/seekers/{$seeker->_id}")
                ->assertStatus(200);

    expect($res->json('image'))->toBe('https://res.cloudinary.com/demo/image/upload/photo.jpg');

    $profile->delete(); $seeker->delete(); $employer->delete();
});

test('showJobSeeker returns created_at and updated_at timestamps', function () {
    [$employer, $token] = fieldsTestEmployer();
    [$seeker, $profile] = fieldsTestSeeker();

    $res = $this->withToken($token)
                ->getJson("/api/employer/seekers/{$seeker->_id}")
                ->assertStatus(200);

    expect($res->json('created_at'))->not->toBeNull();
    expect($res->json('updated_at'))->not->toBeNull();

    $profile->delete(); $seeker->delete(); $employer->delete();
});

// ── job seeker own profile: image present ─────────────────────

test('job seeker own profile response includes image field', function () {
    $seeker  = User::factory()->employee()->create();
    $token   = auth('api')->login($seeker);

    JobSeekerProfile::create([
        'user_id' => $seeker->_id,
        'image'   => 'https://res.cloudinary.com/demo/image/upload/photo.jpg',
    ]);

    $res = $this->withToken($token)
                ->getJson('/api/job-seeker/profile')
                ->assertStatus(200);

    expect($res->json('profile.image'))->toBe('https://res.cloudinary.com/demo/image/upload/photo.jpg');

    $seeker->jobSeekerProfile?->delete();
    $seeker->delete();
});

test('job seeker own profile image is null when not uploaded', function () {
    $seeker = User::factory()->employee()->create();
    $token  = auth('api')->login($seeker);

    $res = $this->withToken($token)
                ->getJson('/api/job-seeker/profile')
                ->assertStatus(200);

    // image key may be absent or null — either is acceptable
    expect($res->json('profile.image'))->toBeNull();

    $seeker->jobSeekerProfile?->delete();
    $seeker->delete();
});

// ── Image upload flow: PUT /api/job-seeker/profile/personal-info ──

test('job seeker can upload profile image and it is stored', function () {
    Storage::fake('cloudinary');

    $seeker = User::factory()->employee()->create();
    $token  = auth('api')->login($seeker);
    $file   = UploadedFile::fake()->image('photo.jpg', 200, 200);

    $res = $this->withToken($token)->put('/api/job-seeker/profile/personal-info', [
        'image' => $file,
    ]);

    $res->assertStatus(200)
        ->assertJsonPath('message', 'Personal information updated successfully');

    $profile = \App\Models\JobSeekerProfile::where('user_id', $seeker->_id)->first();
    expect($profile->image)->not->toBeNull();
    expect($profile->image_public_id)->not->toBeNull();

    $profile->delete();
    $seeker->delete();
});

test('uploaded image url is returned in own profile response', function () {
    Storage::fake('cloudinary');

    $seeker = User::factory()->employee()->create();
    $token  = auth('api')->login($seeker);
    $file   = UploadedFile::fake()->image('avatar.png', 100, 100);

    $this->withToken($token)->put('/api/job-seeker/profile/personal-info', [
        'image' => $file,
    ])->assertStatus(200);

    $res = $this->withToken($token)->getJson('/api/job-seeker/profile')
                ->assertStatus(200);

    expect($res->json('profile.image'))->not->toBeNull();

    \App\Models\JobSeekerProfile::where('user_id', $seeker->_id)->delete();
    $seeker->delete();
});

test('uploaded image url is returned in employer seeker view', function () {
    Storage::fake('cloudinary');

    [$employer, $empToken] = fieldsTestEmployer();
    $seeker  = User::factory()->employee()->create();
    $token   = auth('api')->login($seeker);
    $file    = UploadedFile::fake()->image('headshot.webp', 150, 150);

    $this->withToken($token)->put('/api/job-seeker/profile/personal-info', [
        'image' => $file,
    ])->assertStatus(200);

    $res = $this->withToken($empToken)
                ->getJson("/api/employer/seekers/{$seeker->_id}")
                ->assertStatus(200);

    expect($res->json('image'))->not->toBeNull();

    \App\Models\JobSeekerProfile::where('user_id', $seeker->_id)->delete();
    $seeker->delete();
    $employer->delete();
});

test('uploading a new image replaces the old one on cloudinary', function () {
    Storage::fake('cloudinary');

    $seeker  = User::factory()->employee()->create();
    $token   = auth('api')->login($seeker);

    // Upload first image
    $this->withToken($token)->put('/api/job-seeker/profile/personal-info', [
        'image' => UploadedFile::fake()->image('first.jpg'),
    ])->assertStatus(200);

    $profile = \App\Models\JobSeekerProfile::where('user_id', $seeker->_id)->first();
    $oldPublicId = $profile->image_public_id;
    Storage::disk('cloudinary')->put($oldPublicId, '');

    // Upload second image
    $this->withToken($token)->put('/api/job-seeker/profile/personal-info', [
        'image' => UploadedFile::fake()->image('second.jpg'),
    ])->assertStatus(200);

    Storage::disk('cloudinary')->assertMissing($oldPublicId);

    $profile->refresh();
    expect($profile->image_public_id)->not->toBe($oldPublicId);

    $profile->delete();
    $seeker->delete();
});

test('image upload rejects non-image file types', function () {
    Storage::fake('cloudinary');

    $seeker = User::factory()->employee()->create();
    $token  = auth('api')->login($seeker);

    $this->withToken($token)->put('/api/job-seeker/profile/personal-info', [
        'image' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
    ])->assertStatus(422)
      ->assertJsonStructure(['errors' => ['image']]);

    $seeker->delete();
});

test('image upload rejects files over 2MB', function () {
    Storage::fake('cloudinary');

    $seeker = User::factory()->employee()->create();
    $token  = auth('api')->login($seeker);

    $this->withToken($token)->put('/api/job-seeker/profile/personal-info', [
        'image' => UploadedFile::fake()->image('big.jpg')->size(3000),
    ])->assertStatus(422)
      ->assertJsonStructure(['errors' => ['image']]);

    $seeker->delete();
});
