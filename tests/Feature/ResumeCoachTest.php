<?php

// =============================================================================
// ResumeCoachTest — AI resume coach.
//   POST   /api/job-seeker/coach/sessions        create a session
//   GET    /api/job-seeker/coach/sessions        list sessions
//   GET    /api/job-seeker/coach/sessions/{id}   session messages
//   DELETE /api/job-seeker/coach/sessions/{id}   delete a session
//   POST   /api/job-seeker/coach/chat            chat (service creates/continues session)
//
// The ResumeCoachService (external AI) is mocked, so tests are deterministic.
// =============================================================================

use App\Exceptions\CvAnalysisException;
use App\Models\CoachMessage;
use App\Models\CoachSession;
use App\Models\User;
use App\Services\ResumeCoachService;

beforeEach(function () {
    [$this->seeker, $this->token] = userWithToken('employee');
});

/** A coach session owned by the given user (defaults to the current seeker). */
function coachSession(?User $user = null, string $title = 'Test Session'): CoachSession
{
    return CoachSession::create(['user_id' => (string) ($user ?? test()->seeker)->_id, 'title' => $title]);
}

// ── Create sessions ──────────────────────────────────────────────────────

test('a seeker can create a coach session', function () {
    $this->withToken($this->token)
        ->postJson('/api/job-seeker/coach/sessions', ['title' => 'My coaching session'])
        ->assertCreated()
        ->assertJsonPath('data.title', 'My coaching session');
});

test('a session title defaults when none is given', function () {
    $this->withToken($this->token)
        ->postJson('/api/job-seeker/coach/sessions')
        ->assertCreated()
        ->assertJsonPath('data.title', 'New Session');
});

test('a session title cannot exceed 100 characters', function () {
    $this->withToken($this->token)
        ->postJson('/api/job-seeker/coach/sessions', ['title' => str_repeat('a', 101)])
        ->assertStatus(422)
        ->assertJsonStructure(['title']);
});

// ── List sessions ────────────────────────────────────────────────────────

test('a seeker can list their sessions, newest first', function () {
    coachSession(title: 'First')->update(['created_at' => now()->subMinutes(5)]);
    coachSession(title: 'Second');

    $this->withToken($this->token)
        ->getJson('/api/job-seeker/coach/sessions')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.title', 'Second')
        ->assertJsonPath('data.1.title', 'First');
});

test('a seeker only sees their own sessions', function () {
    coachSession(title: 'Mine');
    coachSession(createUser('employee'), 'Not mine');

    $this->withToken($this->token)
        ->getJson('/api/job-seeker/coach/sessions')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Mine');
});

test('the sessions list is empty when there are none', function () {
    $this->withToken($this->token)
        ->getJson('/api/job-seeker/coach/sessions')
        ->assertOk()
        ->assertJsonPath('data', []);
});

// ── Session messages ─────────────────────────────────────────────────────

test('a seeker can read a sessions messages in chronological order', function () {
    $session = coachSession();
    CoachMessage::create(['session_id' => $session->id, 'role' => 'user', 'content' => 'First', 'created_at' => now()->subSeconds(2)]);
    CoachMessage::create(['session_id' => $session->id, 'role' => 'assistant', 'content' => 'Reply', 'created_at' => now()->subSeconds(1)]);
    CoachMessage::create(['session_id' => $session->id, 'role' => 'user', 'content' => 'Second', 'created_at' => now()]);

    $this->withToken($this->token)
        ->getJson("/api/job-seeker/coach/sessions/{$session->id}")
        ->assertOk()
        ->assertJsonStructure(['data' => [['role', 'content', 'created_at']]])
        ->assertJsonPath('data.0.content', 'First')
        ->assertJsonPath('data.2.content', 'Second');
});

test('reading a session owned by someone else returns 404', function () {
    $session = coachSession(createUser('employee'));

    $this->withToken($this->token)->getJson("/api/job-seeker/coach/sessions/{$session->id}")->assertNotFound();
});

// ── Delete a session ─────────────────────────────────────────────────────

test('a seeker can delete a session and its messages', function () {
    $session = coachSession();
    CoachMessage::create(['session_id' => $session->id, 'role' => 'user', 'content' => 'Hi']);

    $this->withToken($this->token)
        ->deleteJson("/api/job-seeker/coach/sessions/{$session->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Session deleted');

    expect(CoachSession::find($session->id))->toBeNull()
        ->and(CoachMessage::where('session_id', $session->id)->count())->toBe(0);
});

test('a seeker cannot delete another users session', function () {
    $session = coachSession(createUser('employee'));

    $this->withToken($this->token)->deleteJson("/api/job-seeker/coach/sessions/{$session->id}")->assertNotFound();
});

// ── Chat ─────────────────────────────────────────────────────────────────

test('chatting returns the AI response and session id', function () {
    $this->mock(ResumeCoachService::class)
        ->shouldReceive('chat')->once()
        ->andReturn(['response' => 'Focus on quantifying achievements.', 'session_id' => 'sess_123']);

    $this->withToken($this->token)
        ->postJson('/api/job-seeker/coach/chat', ['message' => 'How do I improve my resume?'])
        ->assertOk()
        ->assertJsonPath('response', 'Focus on quantifying achievements.')
        ->assertJsonPath('session_id', 'sess_123');
});

test('chatting forwards the user id, message, and optional session id to the service', function () {
    $this->mock(ResumeCoachService::class)
        ->shouldReceive('chat')->once()
        ->with((string) $this->seeker->_id, 'Follow up', 'sess_1')
        ->andReturn(['response' => 'Sure', 'session_id' => 'sess_1']);

    $this->withToken($this->token)
        ->postJson('/api/job-seeker/coach/chat', ['message' => 'Follow up', 'session_id' => 'sess_1'])
        ->assertOk();
});

test('chat requires a message', function () {
    $this->withToken($this->token)
        ->postJson('/api/job-seeker/coach/chat', [])
        ->assertStatus(422)
        ->assertJsonStructure(['message']);
});

test('a chat message cannot exceed 1000 characters', function () {
    $this->withToken($this->token)
        ->postJson('/api/job-seeker/coach/chat', ['message' => str_repeat('a', 1001)])
        ->assertStatus(422)
        ->assertJsonStructure(['message']);
});

test('chat returns 422 with the service reason when the message is rejected', function () {
    $this->mock(ResumeCoachService::class)
        ->shouldReceive('chat')->once()->andThrow(new CvAnalysisException('Offensive content detected', 422));

    $this->withToken($this->token)
        ->postJson('/api/job-seeker/coach/chat', ['message' => 'Hello'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Resume coach request failed')
        ->assertJsonPath('reason', 'Offensive content detected');
});

test('chat returns 502 when the service is unavailable', function () {
    $this->mock(ResumeCoachService::class)
        ->shouldReceive('chat')->once()->andThrow(new CvAnalysisException('Down', 502));

    $this->withToken($this->token)
        ->postJson('/api/job-seeker/coach/chat', ['message' => 'Hello'])
        ->assertStatus(502)
        ->assertJsonPath('message', 'Resume coach service unavailable');
});

// ── Access control ─────────────────────────────────────────────────────

test('an unauthenticated user cannot access coach endpoints', function () {
    $this->getJson('/api/job-seeker/coach/sessions')->assertUnauthorized();
    $this->postJson('/api/job-seeker/coach/chat', ['message' => 'Hi'])->assertUnauthorized();
});

test('an employer cannot access the seeker coach endpoints', function () {
    $this->withToken(tokenFor('employer'))->getJson('/api/job-seeker/coach/sessions')->assertForbidden();
});
