<?php

// ============================================================
// Tests for AI Resume Coach — sessions + chat history
// ============================================================

use App\Models\CoachMessage;
use App\Models\CoachSession;
use App\Models\User;
use App\Services\ResumeCoachService;
use App\Exceptions\CvAnalysisException;

function makeCoachSeeker(): array
{
    $user  = User::factory()->employee()->create();
    $token = auth('api')->login($user);
    return [$user, $token];
}

function makeSession(User $user, string $title = 'Test Session'): CoachSession
{
    return CoachSession::create(['user_id' => $user->id, 'title' => $title]);
}

afterEach(function () {
    CoachSession::truncate();
    CoachMessage::truncate();
});

// ── POST /api/job-seeker/coach/sessions ───────────────────────

test('job seeker can create a coach session', function () {
    [$user, $token] = makeCoachSeeker();

    $this->withToken($token)
        ->postJson('/api/job-seeker/coach/sessions', ['title' => 'My coaching session'])
        ->assertStatus(201)
        ->assertJsonStructure(['id', 'title', 'created_at'])
        ->assertJsonPath('title', 'My coaching session');

    $user->delete();
});

test('session title defaults to New Conversation', function () {
    [$user, $token] = makeCoachSeeker();

    $this->withToken($token)
        ->postJson('/api/job-seeker/coach/sessions')
        ->assertStatus(201)
        ->assertJsonPath('title', 'New Conversation');

    $user->delete();
});

test('session title cannot exceed 100 chars', function () {
    [$user, $token] = makeCoachSeeker();

    $this->withToken($token)
        ->postJson('/api/job-seeker/coach/sessions', ['title' => str_repeat('a', 101)])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['title']]);

    $user->delete();
});

// ── GET /api/job-seeker/coach/sessions ───────────────────────

test('job seeker can list their sessions', function () {
    [$user, $token] = makeCoachSeeker();
    makeSession($user, 'Session A');
    makeSession($user, 'Session B');

    $this->withToken($token)
        ->getJson('/api/job-seeker/coach/sessions')
        ->assertStatus(200)
        ->assertJsonStructure(['data' => [['id', 'title', 'created_at']]])
        ->assertJsonCount(2, 'data');

    $user->delete();
});

test('job seeker only sees their own sessions', function () {
    [$userA, $tokenA] = makeCoachSeeker();
    [$userB]          = makeCoachSeeker();
    makeSession($userA, 'Mine');
    makeSession($userB, 'Not mine');

    $this->withToken($tokenA)
        ->getJson('/api/job-seeker/coach/sessions')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Mine');

    $userA->delete();
    $userB->delete();
});

test('returns empty list when no sessions', function () {
    [$user, $token] = makeCoachSeeker();

    $this->withToken($token)
        ->getJson('/api/job-seeker/coach/sessions')
        ->assertStatus(200)
        ->assertJsonPath('data', []);

    $user->delete();
});

// ── GET /api/job-seeker/coach/sessions/{id} ──────────────────

test('job seeker can get a session with its messages', function () {
    [$user, $token] = makeCoachSeeker();
    $session = makeSession($user);
    CoachMessage::create(['session_id' => $session->id, 'role' => 'user',      'content' => 'Hello']);
    CoachMessage::create(['session_id' => $session->id, 'role' => 'assistant', 'content' => 'Hi there!']);

    $this->withToken($token)
        ->getJson("/api/job-seeker/coach/sessions/{$session->id}")
        ->assertStatus(200)
        ->assertJsonStructure(['id', 'title', 'messages' => [['role', 'content', 'created_at']]])
        ->assertJsonCount(2, 'messages')
        ->assertJsonPath('messages.0.role', 'user')
        ->assertJsonPath('messages.1.role', 'assistant');

    $user->delete();
});

test('returns 404 for session not owned by user', function () {
    [$userA, $tokenA] = makeCoachSeeker();
    [$userB]          = makeCoachSeeker();
    $session = makeSession($userB);

    $this->withToken($tokenA)
        ->getJson("/api/job-seeker/coach/sessions/{$session->id}")
        ->assertStatus(404);

    $userA->delete();
    $userB->delete();
});

test('returns 404 for non-existent session', function () {
    [$user, $token] = makeCoachSeeker();

    $this->withToken($token)
        ->getJson('/api/job-seeker/coach/sessions/000000000000000000000000')
        ->assertStatus(404);

    $user->delete();
});

// ── DELETE /api/job-seeker/coach/sessions/{id} ───────────────

test('job seeker can delete a session and its messages', function () {
    [$user, $token] = makeCoachSeeker();
    $session = makeSession($user);
    CoachMessage::create(['session_id' => $session->id, 'role' => 'user', 'content' => 'Hi']);

    $this->withToken($token)
        ->deleteJson("/api/job-seeker/coach/sessions/{$session->id}")
        ->assertStatus(200)
        ->assertJsonPath('message', 'Session deleted');

    expect(CoachSession::find($session->id))->toBeNull();
    expect(CoachMessage::where('session_id', $session->id)->count())->toBe(0);

    $user->delete();
});

test('cannot delete another user\'s session', function () {
    [$userA, $tokenA] = makeCoachSeeker();
    [$userB]          = makeCoachSeeker();
    $session = makeSession($userB);

    $this->withToken($tokenA)
        ->deleteJson("/api/job-seeker/coach/sessions/{$session->id}")
        ->assertStatus(404);

    $userA->delete();
    $userB->delete();
});

// ── POST /api/job-seeker/coach/sessions/{id}/chat ────────────

test('job seeker can chat in a session', function () {
    [$user, $token] = makeCoachSeeker();
    $session = makeSession($user);

    $mock = $this->mock(ResumeCoachService::class);
    $mock->shouldReceive('chat')
        ->once()
        ->with('How do I improve my resume?', [])
        ->andReturn('Focus on quantifying achievements.');

    $this->withToken($token)
        ->postJson("/api/job-seeker/coach/sessions/{$session->id}/chat", [
            'message' => 'How do I improve my resume?',
        ])
        ->assertStatus(200)
        ->assertJsonPath('response', 'Focus on quantifying achievements.');

    expect(CoachMessage::where('session_id', $session->id)->count())->toBe(2);

    $user->delete();
});

test('chat persists user and assistant messages', function () {
    [$user, $token] = makeCoachSeeker();
    $session = makeSession($user);

    $mock = $this->mock(ResumeCoachService::class);
    $mock->shouldReceive('chat')->once()->andReturn('Great question!');

    $this->withToken($token)
        ->postJson("/api/job-seeker/coach/sessions/{$session->id}/chat", [
            'message' => 'Any tips?',
        ]);

    $messages = CoachMessage::where('session_id', $session->id)->orderBy('created_at')->get();
    expect($messages[0]->role)->toBe('user');
    expect($messages[0]->content)->toBe('Any tips?');
    expect($messages[1]->role)->toBe('assistant');
    expect($messages[1]->content)->toBe('Great question!');

    $user->delete();
});

test('chat passes conversation history to service', function () {
    [$user, $token] = makeCoachSeeker();
    $session = makeSession($user);

    // Seed existing messages
    CoachMessage::create(['session_id' => $session->id, 'role' => 'user',      'content' => 'First message']);
    CoachMessage::create(['session_id' => $session->id, 'role' => 'assistant', 'content' => 'First reply']);

    $mock = $this->mock(ResumeCoachService::class);
    $mock->shouldReceive('chat')
        ->once()
        ->withArgs(function (string $msg, array $history) {
            return $msg === 'Follow up'
                && count($history) === 2
                && $history[0]['role'] === 'user'
                && $history[1]['role'] === 'assistant';
        })
        ->andReturn('Follow-up reply');

    $this->withToken($token)
        ->postJson("/api/job-seeker/coach/sessions/{$session->id}/chat", [
            'message' => 'Follow up',
        ])
        ->assertStatus(200);

    $user->delete();
});

test('chat auto-updates default session title from first message', function () {
    [$user, $token] = makeCoachSeeker();
    $session = makeSession($user, 'New Conversation');

    $mock = $this->mock(ResumeCoachService::class);
    $mock->shouldReceive('chat')->once()->andReturn('Sure!');

    $this->withToken($token)
        ->postJson("/api/job-seeker/coach/sessions/{$session->id}/chat", [
            'message' => 'How to write a cover letter?',
        ]);

    expect(CoachSession::find($session->id)->title)->toBe('How to write a cover letter?');

    $user->delete();
});

test('chat does not overwrite a custom session title', function () {
    [$user, $token] = makeCoachSeeker();
    $session = makeSession($user, 'My Custom Title');

    $mock = $this->mock(ResumeCoachService::class);
    $mock->shouldReceive('chat')->once()->andReturn('Reply');

    $this->withToken($token)
        ->postJson("/api/job-seeker/coach/sessions/{$session->id}/chat", [
            'message' => 'Some message',
        ]);

    expect(CoachSession::find($session->id)->title)->toBe('My Custom Title');

    $user->delete();
});

test('chat requires message field', function () {
    [$user, $token] = makeCoachSeeker();
    $session = makeSession($user);

    $this->withToken($token)
        ->postJson("/api/job-seeker/coach/sessions/{$session->id}/chat", [])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['message']]);

    $user->delete();
});

test('chat message cannot exceed 1000 chars', function () {
    [$user, $token] = makeCoachSeeker();
    $session = makeSession($user);

    $this->withToken($token)
        ->postJson("/api/job-seeker/coach/sessions/{$session->id}/chat", [
            'message' => str_repeat('a', 1001),
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['message']]);

    $user->delete();
});

test('chat returns 404 for session not owned by user', function () {
    [$userA, $tokenA] = makeCoachSeeker();
    [$userB]          = makeCoachSeeker();
    $session = makeSession($userB);

    $this->withToken($tokenA)
        ->postJson("/api/job-seeker/coach/sessions/{$session->id}/chat", [
            'message' => 'Hello',
        ])
        ->assertStatus(404);

    $userA->delete();
    $userB->delete();
});

test('chat returns 422 when service rejects the message', function () {
    [$user, $token] = makeCoachSeeker();
    $session = makeSession($user);

    $mock = $this->mock(ResumeCoachService::class);
    $mock->shouldReceive('chat')->once()->andThrow(new CvAnalysisException('Bad input', 422));

    $this->withToken($token)
        ->postJson("/api/job-seeker/coach/sessions/{$session->id}/chat", [
            'message' => 'Hello',
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Resume coach request failed');

    $user->delete();
});

test('chat returns 502 when service is unavailable', function () {
    [$user, $token] = makeCoachSeeker();
    $session = makeSession($user);

    $mock = $this->mock(ResumeCoachService::class);
    $mock->shouldReceive('chat')->once()->andThrow(new CvAnalysisException('Down', 502));

    $this->withToken($token)
        ->postJson("/api/job-seeker/coach/sessions/{$session->id}/chat", [
            'message' => 'Hello',
        ])
        ->assertStatus(502)
        ->assertJsonPath('message', 'Resume coach service unavailable');

    $user->delete();
});

// ── Auth / Role guards ────────────────────────────────────────

test('unauthenticated user cannot access coach endpoints', function () {
    $this->getJson('/api/job-seeker/coach/sessions')->assertStatus(401);
    $this->postJson('/api/job-seeker/coach/sessions')->assertStatus(401);
});

test('employer cannot access job seeker coach endpoints', function () {
    $employer = User::factory()->employer()->create();
    $token    = auth('api')->login($employer);

    $this->withToken($token)->getJson('/api/job-seeker/coach/sessions')->assertStatus(403);

    $employer->delete();
});

// ── Additional coverage ───────────────────────────────────────

test('sessions list is ordered newest first', function () {
    [$user, $token] = makeCoachSeeker();

    $first  = CoachSession::create(['user_id' => $user->id, 'title' => 'First',  'created_at' => now()->subMinutes(5)]);
    $second = CoachSession::create(['user_id' => $user->id, 'title' => 'Second', 'created_at' => now()]);

    $response = $this->withToken($token)
        ->getJson('/api/job-seeker/coach/sessions')
        ->assertStatus(200);

    expect($response->json('data.0.title'))->toBe('Second');
    expect($response->json('data.1.title'))->toBe('First');

    $user->delete();
});

test('session messages are returned in chronological order', function () {
    [$user, $token] = makeCoachSeeker();
    $session = makeSession($user);

    CoachMessage::create(['session_id' => $session->id, 'role' => 'user',      'content' => 'First',  'created_at' => now()->subSeconds(2)]);
    CoachMessage::create(['session_id' => $session->id, 'role' => 'assistant', 'content' => 'Reply',  'created_at' => now()->subSeconds(1)]);
    CoachMessage::create(['session_id' => $session->id, 'role' => 'user',      'content' => 'Second', 'created_at' => now()]);

    $response = $this->withToken($token)
        ->getJson("/api/job-seeker/coach/sessions/{$session->id}")
        ->assertStatus(200);

    expect($response->json('messages.0.content'))->toBe('First');
    expect($response->json('messages.1.content'))->toBe('Reply');
    expect($response->json('messages.2.content'))->toBe('Second');

    $user->delete();
});

test('chat 422 response includes reason from service', function () {
    [$user, $token] = makeCoachSeeker();
    $session = makeSession($user);

    $mock = $this->mock(ResumeCoachService::class);
    $mock->shouldReceive('chat')->once()->andThrow(new CvAnalysisException('Offensive content detected', 422));

    $this->withToken($token)
        ->postJson("/api/job-seeker/coach/sessions/{$session->id}/chat", [
            'message' => 'Hello',
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Resume coach request failed')
        ->assertJsonPath('reason', 'Offensive content detected');

    $user->delete();
});

test('auto-title is truncated to 60 chars for long first message', function () {
    [$user, $token] = makeCoachSeeker();
    $session = makeSession($user, 'New Conversation');

    $mock = $this->mock(ResumeCoachService::class);
    $mock->shouldReceive('chat')->once()->andReturn('Got it!');

    $longMessage = str_repeat('a', 80);

    $this->withToken($token)
        ->postJson("/api/job-seeker/coach/sessions/{$session->id}/chat", [
            'message' => $longMessage,
        ]);

    expect(CoachSession::find($session->id)->title)->toBe(str_repeat('a', 60));

    $user->delete();
});

test('messages are not saved when service call fails', function () {
    [$user, $token] = makeCoachSeeker();
    $session = makeSession($user);

    $mock = $this->mock(ResumeCoachService::class);
    $mock->shouldReceive('chat')->once()->andThrow(new CvAnalysisException('Down', 502));

    $this->withToken($token)
        ->postJson("/api/job-seeker/coach/sessions/{$session->id}/chat", [
            'message' => 'Hello',
        ])
        ->assertStatus(502);

    expect(CoachMessage::where('session_id', $session->id)->count())->toBe(0);

    $user->delete();
});
