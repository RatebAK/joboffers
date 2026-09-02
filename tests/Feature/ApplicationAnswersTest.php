<?php

// =============================================================================
// ApplicationAnswersTest
//
// Covers the screening-question answers on a job application:
//   - A seeker's answers are stored, paired with the job post's questions
//   - Required questions must be answered (422 otherwise)
//   - Optional questions may be skipped
//   - Answers for questions not on the job post are ignored
//   - The employer sees the stored answers when reviewing applications
//
// Cleanup is handled by truncating collections in afterEach, so each test body
// stays focused on the behaviour under test.
// =============================================================================

use App\Models\Application;
use App\Models\JobPost;
use App\Models\User;

// ── Fixtures ─────────────────────────────────────────────────────────────

/** Create an active job post owned by $employerId with the given questions. */
function jobWithQuestions(string $employerId, array $questions): JobPost
{
    return JobPost::create([
        'title'        => 'Backend Developer',
        'description'  => 'Build APIs.',
        'requirements' => 'PHP.',
        'company_name' => 'Answers Co',
        'job_type'     => 'full_time',
        'employer_id'  => $employerId,
        'is_active'    => true,
        'questions'    => $questions,
    ]);
}

beforeEach(function () {
    $this->employer = User::factory()->employer()->create();
    $this->seeker   = User::factory()->employee()->create();
});

// ── Storing answers ────────────────────────────────────────────────────────

test('answers to job post questions are stored on the application', function () {
    $job = jobWithQuestions((string) $this->employer->_id, [
        ['question' => 'Why do you want this job?', 'required' => true],
        ['question' => 'Are you open to relocation?', 'required' => false],
    ]);

    $this->withToken(auth('api')->login($this->seeker))
        ->postJson('/api/job-seeker/apply', [
            'job_post_id' => (string) $job->_id,
            'answers'     => [
                ['question' => 'Why do you want this job?', 'answer' => 'I love building APIs.'],
                ['question' => 'Are you open to relocation?', 'answer' => 'Yes.'],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('application.answers.0.question', 'Why do you want this job?')
        ->assertJsonPath('application.answers.0.answer', 'I love building APIs.')
        ->assertJsonPath('application.answers.1.answer', 'Yes.');
});

test('a required question with no answer is rejected', function () {
    $job = jobWithQuestions((string) $this->employer->_id, [
        ['question' => 'Why do you want this job?', 'required' => true],
    ]);

    $this->withToken(auth('api')->login($this->seeker))
        ->postJson('/api/job-seeker/apply', [
            'job_post_id' => (string) $job->_id,
            'answers'     => [],
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.answers.0', 'Why do you want this job?');

    expect(Application::where('user_id', $this->seeker->_id)->exists())->toBeFalse();
});

test('an optional question may be left unanswered', function () {
    $job = jobWithQuestions((string) $this->employer->_id, [
        ['question' => 'Any portfolio link?', 'required' => false],
    ]);

    $this->withToken(auth('api')->login($this->seeker))
        ->postJson('/api/job-seeker/apply', [
            'job_post_id' => (string) $job->_id,
        ])
        ->assertCreated()
        ->assertJsonPath('application.answers', []);
});

test('answers for questions not on the job post are ignored', function () {
    $job = jobWithQuestions((string) $this->employer->_id, [
        ['question' => 'Why do you want this job?', 'required' => true],
    ]);

    $this->withToken(auth('api')->login($this->seeker))
        ->postJson('/api/job-seeker/apply', [
            'job_post_id' => (string) $job->_id,
            'answers'     => [
                ['question' => 'Why do you want this job?', 'answer' => 'Growth.'],
                ['question' => 'Made up question?', 'answer' => 'Should be dropped.'],
            ],
        ])
        ->assertCreated()
        ->assertJsonCount(1, 'application.answers')
        ->assertJsonPath('application.answers.0.question', 'Why do you want this job?');
});

test('a job post with no questions accepts an application with no answers', function () {
    $job = jobWithQuestions((string) $this->employer->_id, []);

    $this->withToken(auth('api')->login($this->seeker))
        ->postJson('/api/job-seeker/apply', [
            'job_post_id' => (string) $job->_id,
        ])
        ->assertCreated()
        ->assertJsonPath('application.answers', []);
});

// ── Validation of the answers payload shape ─────────────────────────────────

test('an answer entry missing its answer text fails validation', function () {
    $job = jobWithQuestions((string) $this->employer->_id, [
        ['question' => 'Why?', 'required' => false],
    ]);

    $this->withToken(auth('api')->login($this->seeker))
        ->postJson('/api/job-seeker/apply', [
            'job_post_id' => (string) $job->_id,
            'answers'     => [
                ['question' => 'Why?'],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('answers.0.answer');
});

// ── Employer visibility ───────────────────────────────────────────────────

test('the employer sees the answers when reviewing applications', function () {
    $job = jobWithQuestions((string) $this->employer->_id, [
        ['question' => 'Why do you want this job?', 'required' => true],
    ]);

    $this->withToken(auth('api')->login($this->seeker))
        ->postJson('/api/job-seeker/apply', [
            'job_post_id' => (string) $job->_id,
            'answers'     => [
                ['question' => 'Why do you want this job?', 'answer' => 'Passion for the craft.'],
            ],
        ])
        ->assertCreated();

    $response = $this->withToken(auth('api')->login($this->employer))
        ->getJson("/api/employer/jobs/{$job->_id}/applications")
        ->assertOk();

    $answers = $response->json('applications.data.0.answers');

    expect($answers[0]['question'])->toBe('Why do you want this job?');
    expect($answers[0]['answer'])->toBe('Passion for the craft.');
});
