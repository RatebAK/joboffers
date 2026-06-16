<?php

// ============================================================
// Tests for AI Resume Coach chat endpoint.
// Job seekers can ask for career advice and resume tips.
// ============================================================

use App\Models\User;
use App\Services\ResumeCoachService;
use App\Exceptions\CvAnalysisException;

function coachUser(): array
{
    $seeker = User::factory()->employee()->create();
    $token = auth('api')->login($seeker);

    return [$seeker, $token];
}

// ── POST /api/job-seeker/coach/chat ────────────────────────────

test('job seeker can chat with resume coach', function () {
    [$seeker, $token] = coachUser();

    $mock = $this->mock(ResumeCoachService::class);
    $mock->shouldReceive('chat')
        ->once()
        ->with('give me advice on improving my resume')
        ->andReturn('To improve your resume, focus on quantifying your achievements with specific metrics and numbers.');

    $response = $this->withToken($token)->postJson('/api/job-seeker/coach/chat', [
        'message' => 'give me advice on improving my resume',
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure(['response'])
        ->assertJsonPath('response', 'To improve your resume, focus on quantifying your achievements with specific metrics and numbers.');

    $seeker->delete();
});

test('coach returns job market insights', function () {
    [$seeker, $token] = coachUser();

    $mock = $this->mock(ResumeCoachService::class);
    $mock->shouldReceive('chat')
        ->once()
        ->with('give me a 2 sentence idea of the job market')
        ->andReturn('The job market for Data Scientists and AI professionals is highly competitive yet rapidly expanding. Focus on showcasing your unique blend of skills and continuous learning.');

    $response = $this->withToken($token)->postJson('/api/job-seeker/coach/chat', [
        'message' => 'give me a 2 sentence idea of the job market',
    ]);

    $response->assertStatus(200);
    expect($response->json('response'))->toContain('job market');

    $seeker->delete();
});

test('coach chat requires message', function () {
    [, $token] = coachUser();

    $this->withToken($token)->postJson('/api/job-seeker/coach/chat', [])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['message']]);
});

test('coach chat rejects empty message', function () {
    [, $token] = coachUser();

    $this->withToken($token)->postJson('/api/job-seeker/coach/chat', [
        'message' => '',
    ])->assertStatus(422)
      ->assertJsonStructure(['errors' => ['message']]);
});

test('coach chat rejects message over 1000 chars', function () {
    [, $token] = coachUser();

    $this->withToken($token)->postJson('/api/job-seeker/coach/chat', [
        'message' => str_repeat('a', 1001),
    ])->assertStatus(422)
      ->assertJsonStructure(['errors' => ['message']]);
});

test('coach chat accepts message up to 1000 chars', function () {
    [$seeker, $token] = coachUser();

    $mock = $this->mock(ResumeCoachService::class);
    $mock->shouldReceive('chat')
        ->once()
        ->andReturn('Here is my detailed advice...');

    $this->withToken($token)->postJson('/api/job-seeker/coach/chat', [
        'message' => str_repeat('a', 1000),
    ])->assertStatus(200);

    $seeker->delete();
});

test('coach chat handles various question types', function () {
    [$seeker, $token] = coachUser();

    $mock = $this->mock(ResumeCoachService::class);
    $mock->shouldReceive('chat')
        ->once()
        ->with('What skills should I learn?')
        ->andReturn('Consider learning cloud technologies, AI/ML frameworks, and modern development practices.');

    $response = $this->withToken($token)->postJson('/api/job-seeker/coach/chat', [
        'message' => 'What skills should I learn?',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('response', 'Consider learning cloud technologies, AI/ML frameworks, and modern development practices.');

    $seeker->delete();
});

test('coach chat returns 422 when service fails', function () {
    [$seeker, $token] = coachUser();

    $mock = $this->mock(ResumeCoachService::class);
    $mock->shouldReceive('chat')
        ->once()
        ->andThrow(new CvAnalysisException('Invalid message format', 422));

    $this->withToken($token)->postJson('/api/job-seeker/coach/chat', [
        'message' => 'test',
    ])->assertStatus(422)
      ->assertJsonPath('message', 'Resume coach request failed');

    $seeker->delete();
});

test('coach chat returns 502 when service unavailable', function () {
    [$seeker, $token] = coachUser();

    $mock = $this->mock(ResumeCoachService::class);
    $mock->shouldReceive('chat')
        ->once()
        ->andThrow(new CvAnalysisException('Service unavailable', 502));

    $this->withToken($token)->postJson('/api/job-seeker/coach/chat', [
        'message' => 'test',
    ])->assertStatus(502)
      ->assertJsonPath('message', 'Resume coach service unavailable');

    $seeker->delete();
});

test('unauthenticated user cannot chat with coach', function () {
    $this->postJson('/api/job-seeker/coach/chat', [
        'message' => 'test',
    ])->assertStatus(401);
});

test('employer cannot use job seeker coach endpoint', function () {
    $employer = User::factory()->employer()->create();
    $token = auth('api')->login($employer);

    $this->withToken($token)->postJson('/api/job-seeker/coach/chat', [
        'message' => 'test',
    ])->assertStatus(403);

    $employer->delete();
});

test('coach provides career guidance', function () {
    [$seeker, $token] = coachUser();

    $mock = $this->mock(ResumeCoachService::class);
    $mock->shouldReceive('chat')
        ->once()
        ->with('How do I negotiate salary?')
        ->andReturn('Research market rates, know your value, and be prepared to discuss your accomplishments confidently.');

    $response = $this->withToken($token)->postJson('/api/job-seeker/coach/chat', [
        'message' => 'How do I negotiate salary?',
    ]);

    $response->assertStatus(200);
    expect($response->json('response'))->toBeString();
    expect($response->json('response'))->not->toBeEmpty();

    $seeker->delete();
});

test('coach handles multiple questions in same session', function () {
    [$seeker, $token] = coachUser();

    $mock = $this->mock(ResumeCoachService::class);

    // First question
    $mock->shouldReceive('chat')
        ->once()
        ->with('What is a good resume length?')
        ->andReturn('A resume should typically be 1-2 pages for most professionals.');

    $response1 = $this->withToken($token)->postJson('/api/job-seeker/coach/chat', [
        'message' => 'What is a good resume length?',
    ]);

    $response1->assertStatus(200);

    // Second question
    $mock->shouldReceive('chat')
        ->once()
        ->with('How do I write a cover letter?')
        ->andReturn('Start with a strong opening, highlight relevant experience, and explain why you are interested in the role.');

    $response2 = $this->withToken($token)->postJson('/api/job-seeker/coach/chat', [
        'message' => 'How do I write a cover letter?',
    ]);

    $response2->assertStatus(200);

    $seeker->delete();
});
