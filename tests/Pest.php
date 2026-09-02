<?php

use App\Models\CompanyProfile;
use App\Models\JobPost;
use App\Models\User;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case & Database Isolation
|--------------------------------------------------------------------------
|
| Every Feature test runs against the MongoDB test database and starts from a
| clean slate. The RefreshMongoDatabase concern drops all collections before
| each test, so individual tests never need to clean up after themselves.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshMongoDatabase::class)
    ->beforeEach(fn () => $this->setUpRefreshMongoDatabase())
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Authentication Helpers
|--------------------------------------------------------------------------
|
| Small, composable helpers used across the suite to create users of a given
| role and authenticate as them. Prefer these over hand-rolling users and
| tokens in each test file.
|
*/

/**
 * Create a user with the given role.
 *
 * @param  string  $role  admin | employer | employee
 */
function createUser(string $role = 'employee', array $attributes = []): User
{
    return User::factory()->{$role}()->create($attributes);
}

/**
 * Create a user of the given role and return a JWT for them.
 */
function tokenFor(string $role, array $attributes = []): string
{
    return auth('api')->login(createUser($role, $attributes));
}

/**
 * Create a user of the given role and return [User, token].
 *
 * @return array{0: User, 1: string}
 */
function userWithToken(string $role, array $attributes = []): array
{
    $user = createUser($role, $attributes);

    return [$user, auth('api')->login($user)];
}

/**
 * Replace the CvAnalysisService with a mock that returns the given analysis.
 *
 * Keeps resume/CV tests fully offline. Pass an array to succeed, or a
 * CvAnalysisException to simulate a parse (422) / service (5xx) failure.
 */
function fakeCvAnalysis(array|\App\Exceptions\CvAnalysisException $result = []): void
{
    $mock = test()->mock(\App\Services\CvAnalysisService::class);
    $expectation = $mock->shouldReceive('analyze');

    $result instanceof \App\Exceptions\CvAnalysisException
        ? $expectation->andThrow($result)
        : $expectation->andReturn($result);
}

/**
 * Replace the DocumentUploadService with a mock that "stores" a document
 * without touching Cloudinary. Returns the fake StoredDocument so tests can
 * assert on its public id / resource type.
 */
function fakeDocumentUpload(): \App\Services\StoredDocument
{
    $document = new \App\Services\StoredDocument(
        url: 'https://res.cloudinary.com/demo/raw/upload/v1/job-seeker-cvs/cv_abc.txt',
        publicId: 'job-seeker-cvs/cv_abc.txt',
        resourceType: 'raw',
        mimeType: 'application/pdf',
        originalName: 'my_cv.pdf',
    );

    $mock = test()->mock(\App\Services\DocumentUploadService::class);
    $mock->shouldReceive('upload')->andReturn($document);
    $mock->shouldReceive('assertDeliverable')->andReturnNull();
    $mock->shouldReceive('delete')->andReturnNull();

    return $document;
}

/**
 * Hash a password the way the app does in the test environment.
 *
 * Bcrypt is unavailable here, so the app falls back to a salted sha256 hash
 * (see AuthController). Users created for login/token tests must be stored with
 * this hash so that POST /api/auth/login can verify the plaintext password.
 */
function testPasswordHash(string $plain): string
{
    return hash('sha256', $plain.'salt');
}

/*
|--------------------------------------------------------------------------
| Domain Builders
|--------------------------------------------------------------------------
|
| Factory-style helpers for the domain objects that tests build repeatedly.
|
*/

/**
 * Create a company profile owned by the given employer.
 */
function createCompanyFor(User $employer, array $attributes = []): CompanyProfile
{
    return CompanyProfile::create(array_merge([
        'employer_id' => (string) $employer->_id,
        'name'        => 'Test Company',
        'slug'        => 'test-company-'.uniqid(),
    ], $attributes));
}

/**
 * Create an active job post owned by the given employer.
 */
function createJob(User $employer, array $attributes = []): JobPost
{
    return JobPost::create(array_merge([
        'title'                => 'Test Engineer',
        'description'          => 'Write tests.',
        'requirements'         => 'PHP.',
        'company_name'         => 'TestCo',
        'job_type'             => 'full_time',
        'work_mode'            => 'remote',
        'city'                 => 'Beirut',
        'vacancies'            => 1,
        'communication_method' => 'by_forsa',
        'employer_id'          => (string) $employer->_id,
        'is_active'            => true,
    ], $attributes));
}

/**
 * Create a job seeker (employee) with an attached JobSeekerProfile.
 *
 * @return array{0: User, 1: \App\Models\JobSeekerProfile}
 */
function createSeekerWithProfile(array $userAttributes = [], array $profileAttributes = []): array
{
    $user    = createUser('employee', $userAttributes);
    $profile = \App\Models\JobSeekerProfile::create(array_merge(
        ['user_id' => (string) $user->_id],
        $profileAttributes,
    ));

    return [$user, $profile];
}

/**
 * Create a meeting between an organizer and an invitee.
 *
 * Defaults to a pending video call three days out; override any field via
 * $attributes (status, proposed_date, previous_schedules, etc.).
 */
function createMeeting(User $organizer, User $invitee, array $attributes = []): \App\Models\Meeting
{
    return \App\Models\Meeting::create(array_merge([
        'organizer_id'              => (string) $organizer->_id,
        'invitee_id'                => (string) $invitee->_id,
        'title'                     => 'Test Meeting',
        'meeting_type'              => 'video_call',
        'proposed_date'             => now()->addDays(3)->format('Y-m-d'),
        'proposed_start_time'       => '10:00',
        'proposed_duration_minutes' => 60,
        'status'                    => 'pending',
        'notes'                     => [],
        'previous_schedules'        => [],
    ], $attributes));
}

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});
