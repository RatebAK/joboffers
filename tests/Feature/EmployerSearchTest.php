<?php

// =============================================================================
// EmployerSearchTest — employer-facing seeker search and profile view.
//   GET /api/employer/seekers              filtered talent search
//   GET /api/employer/seekers/{userId}     a seeker's detailed profile
//
// Covers filters, pagination, the detailed field set, image upload flow,
// privacy (sensitive fields excluded), 404s, and access control.
// =============================================================================

use App\Models\JobSeekerProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    [$this->employer, $this->token] = userWithToken('employer');
});

/** A searchable, actively-seeking seeker with a profile. */
function searchableSeeker(array $overrides = []): array
{
    return createSeekerWithProfile([], array_merge([
        'is_actively_seeking' => true,
        'current_job_title'   => 'Software Engineer',
        'ai_skills'           => ['PHP', 'Laravel', 'MongoDB'],
        'ats_score'           => 70,
        'ai_location'         => 'Beirut, Lebanon',
        'ai_summary'          => 'Experienced backend developer.',
        'ai_email'            => 'private@example.com',
        'ai_phone'            => '+1234567890',
    ], $overrides));
}

// ── Index: GET /api/employer/seekers ─────────────────────────────────────

test('an employer can list actively-seeking seekers', function () {
    searchableSeeker();

    $this->withToken($this->token)->getJson('/api/employer/seekers')
        ->assertOk()
        ->assertJsonStructure(['seekers' => ['data', 'current_page', 'per_page', 'total']]);
});

test('seekers who are not actively seeking are excluded', function () {
    [$seeker] = searchableSeeker(['is_actively_seeking' => false]);

    $ids = collect($this->withToken($this->token)->getJson('/api/employer/seekers')->assertOk()->json('seekers.data'))
        ->pluck('user_id');

    expect($ids)->not->toContain((string) $seeker->_id);
});

test('seekers can be filtered by one or more skills', function (string $query, array $expected) {
    searchableSeeker(['ai_skills' => ['React', 'TypeScript', 'Node.js']]);

    foreach ($this->withToken($this->token)->getJson("/api/employer/seekers?skills={$query}")->assertOk()->json('seekers.data') as $s) {
        $skills = array_map('strtolower', $s['ai_skills'] ?? []);
        foreach ($expected as $e) {
            expect($skills)->toContain($e);
        }
    }
})->with([
    'single'   => ['React', ['react']],
    'multiple' => ['React,TypeScript', ['react', 'typescript']],
]);

test('seekers can be filtered by an ATS score range', function () {
    searchableSeeker(['ats_score' => 85]);
    searchableSeeker(['ats_score' => 40]);

    foreach ($this->withToken($this->token)->getJson('/api/employer/seekers?min_ats_score=80')->assertOk()->json('seekers.data') as $s) {
        expect($s['ats_score'])->toBeGreaterThanOrEqual(80);
    }
});

test('seekers can be filtered by location', function () {
    searchableSeeker(['ai_location' => 'Dubai, UAE']);

    $found = collect($this->withToken($this->token)->getJson('/api/employer/seekers?location=Dubai')->assertOk()->json('seekers.data'))
        ->first(fn ($s) => str_contains(strtolower($s['ai_location'] ?? ''), 'dubai'));

    expect($found)->not->toBeNull();
});

test('seekers can be filtered by a keyword matching title or summary', function (string $field, string $value) {
    searchableSeeker([$field => $value]);

    $found = collect($this->withToken($this->token)->getJson("/api/employer/seekers?keyword={$value}")->assertOk()->json('seekers.data'))
        ->first(fn ($s) => str_contains($s[$field] ?? '', $value));

    expect($found)->not->toBeNull();
})->with([
    'title'   => ['current_job_title', 'UniqueDevTitle123'],
    'summary' => ['ai_summary', 'XYZ_UNIQUE_SUMMARY_TERM'],
]);

// ── Show: GET /api/employer/seekers/{userId} ─────────────────────────────

test('an employer can view a seekers detailed profile', function () {
    [$seeker, $profile] = createSeekerWithProfile([], [
        'is_actively_seeking' => true,
        'first_name' => 'Jane', 'last_name' => 'Smith', 'full_name' => 'Jane Smith',
        'image' => 'https://res.cloudinary.com/demo/image/upload/photo.jpg',
        'phone' => '+961 70 123456', 'gender' => 'female', 'city' => 'Beirut',
    ]);

    $this->withToken($this->token)->getJson("/api/employer/seekers/{$seeker->_id}")
        ->assertOk()
        ->assertJsonPath('id', (string) $profile->_id)
        ->assertJsonPath('user_id', (string) $seeker->_id)
        ->assertJsonPath('name', $seeker->name)
        ->assertJsonPath('email', $seeker->email)
        ->assertJsonPath('full_name', 'Jane Smith')
        ->assertJsonPath('image', 'https://res.cloudinary.com/demo/image/upload/photo.jpg')
        ->assertJsonStructure(['created_at', 'updated_at']);
});

test('the detailed seeker profile excludes sensitive contact fields', function () {
    [$seeker] = searchableSeeker();

    $profile = $this->withToken($this->token)->getJson("/api/employer/seekers/{$seeker->_id}")->assertOk();

    // showJobSeeker returns the profile fields at the top level; contact AI fields must not leak.
    expect($profile->json())->not->toHaveKey('ai_email')
        ->and($profile->json())->not->toHaveKey('ai_phone');
});

test('viewing a seeker returns 404 when the user, profile, or role is wrong', function (callable $target) {
    $id = $target();

    $this->withToken($this->token)->getJson("/api/employer/seekers/{$id}")->assertNotFound();
})->with([
    'unknown id'    => [fn () => '000000000000000000000000'],
    'no profile'    => [fn () => (string) createUser('employee')->_id],
    'not a seeker'  => [fn () => (string) createUser('employer')->_id],
]);

// ── Image upload via personal-info (Cloudinary faked) ────────────────────

test('a seeker can upload a profile image, which appears in both views', function () {
    Storage::fake('cloudinary');
    [$seeker, $seekerToken] = userWithToken('employee');

    $this->withToken($seekerToken)->put('/api/job-seeker/profile/personal-info', [
        'image' => UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg'),
    ])->assertOk();

    $profile = JobSeekerProfile::where('user_id', (string) $seeker->_id)->first();
    expect($profile->image)->not->toBeNull()
        ->and($profile->image_public_id)->not->toBeNull();

    // Visible in the employer's detailed view.
    expect($this->withToken($this->token)->getJson("/api/employer/seekers/{$seeker->_id}")->assertOk()->json('image'))
        ->not->toBeNull();
});

test('uploading a new image replaces the previous one', function () {
    Storage::fake('cloudinary');
    [$seeker, $token] = userWithToken('employee');

    $this->withToken($token)->put('/api/job-seeker/profile/personal-info', ['image' => UploadedFile::fake()->create('first.jpg', 50, 'image/jpeg')])->assertOk();
    $oldPublicId = JobSeekerProfile::where('user_id', (string) $seeker->_id)->first()->image_public_id;
    Storage::disk('cloudinary')->put($oldPublicId, '');

    $this->withToken($token)->put('/api/job-seeker/profile/personal-info', ['image' => UploadedFile::fake()->create('second.jpg', 50, 'image/jpeg')])->assertOk();

    Storage::disk('cloudinary')->assertMissing($oldPublicId);
    expect(JobSeekerProfile::where('user_id', (string) $seeker->_id)->first()->image_public_id)->not->toBe($oldPublicId);
});

test('image upload rejects a non-image or oversized file', function (UploadedFile $file) {
    Storage::fake('cloudinary');
    $token = tokenFor('employee');

    $this->withToken($token)->put('/api/job-seeker/profile/personal-info', ['image' => $file])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['image']]);
})->with([
    'non-image' => [fn () => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')],
    'too large' => [fn () => UploadedFile::fake()->create('big.jpg', 3000, 'image/jpeg')],
]);

// ── Access control ─────────────────────────────────────────────────────

test('an unauthenticated user cannot search seekers', function () {
    $this->getJson('/api/employer/seekers')->assertUnauthorized();
});

test('a job seeker cannot access the seeker search endpoint', function () {
    $this->withToken(tokenFor('employee'))->getJson('/api/employer/seekers')->assertForbidden();
});
