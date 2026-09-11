<?php

// =============================================================================
// ResumeCoachTest — AI resume coach.
//   POST   /api/job-seeker/coach/sessions        create a session
//   GET    /api/job-seeker/coach/sessions        list AI sessions
//   GET    /api/job-seeker/coach/sessions/{id}   AI session messages
//   DELETE /api/job-seeker/coach/sessions/{id}   delete a local session
//   POST   /api/job-seeker/coach/chat            chat (AI service creates/continues session)
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

/** A local coach session owned by the given user (used by create/delete tests). */
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

test('a seeker can list the sessions returned by the AI service', function () {
    $this->mock(ResumeCoachService::class)
        ->shouldReceive('listSessions')->once()
        ->with((string) $this->seeker->_id)
        ->andReturn([
            ['session_id' => 'remote-session-2', 'title' => 'Second', 'updated_at' => '2026-09-07T12:00:00Z'],
            ['session_id' => 'remote-session-1', 'title' => 'First', 'updated_at' => '2026-09-07T11:00:00Z'],
        ]);

    $this->withToken($this->token)
        ->getJson('/api/job-seeker/coach/sessions')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', 'remote-session-2')
        ->assertJsonPath('data.0.title', 'Second')
        ->assertJsonPath('data.0.created_at', null)
        ->assertJsonPath('data.1.id', 'remote-session-1');
});

test('a seeker receives only the AI sessions requested for their user id', function () {
    $this->mock(ResumeCoachService::class)
        ->shouldReceive('listSessions')->once()
        ->with((string) $this->seeker->_id)
        ->andReturn([
            ['session_id' => 'mine', 'title' => 'Mine', 'updated_at' => '2026-09-07T12:00:00Z'],
        ]);

    $this->withToken($this->token)
        ->getJson('/api/job-seeker/coach/sessions')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', 'mine');
});

test('the sessions list is empty when the AI service returns no sessions', function () {
    $this->mock(ResumeCoachService::class)
        ->shouldReceive('listSessions')->once()
        ->with((string) $this->seeker->_id)
        ->andReturn([]);

    $this->withToken($this->token)
        ->getJson('/api/job-seeker/coach/sessions')
        ->assertOk()
        ->assertJsonPath('data', []);
});

// ── Session messages ─────────────────────────────────────────────────────

test('a seeker can read AI session messages in chronological order', function () {
    $this->mock(ResumeCoachService::class)
        ->shouldReceive('getSessionMessages')->once()
        ->with('remote-session-1')
        ->andReturn([
            ['role' => 'user', 'message' => 'First', 'timestamp' => '2026-09-07T11:00:00Z'],
            ['role' => 'assistant', 'message' => 'Reply', 'timestamp' => '2026-09-07T11:00:01Z'],
            ['role' => 'user', 'message' => 'Second', 'timestamp' => '2026-09-07T11:00:02Z'],
        ]);

    $this->withToken($this->token)
        ->getJson('/api/job-seeker/coach/sessions/remote-session-1')
        ->assertOk()
        ->assertJsonStructure(['data' => [['role', 'content', 'created_at']]])
        ->assertJsonPath('data.0.content', 'First')
        ->assertJsonPath('data.2.content', 'Second')
        ->assertJsonPath('data.2.created_at', '2026-09-07T11:00:02Z');
});

test('reading a missing AI session returns 404', function () {
    $this->mock(ResumeCoachService::class)
        ->shouldReceive('getSessionMessages')->once()
        ->with('missing-session')
        ->andThrow(new CvAnalysisException('Session not found', 404));

    $this->withToken($this->token)
        ->getJson('/api/job-seeker/coach/sessions/missing-session')
        ->assertNotFound();
});

// ── Delete a local session ───────────────────────────────────────────────

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
