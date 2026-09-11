<?php

// =============================================================================
// ResumeCoachServiceTest — unit tests for ResumeCoachService URL resolution.
//
// Verifies that the coach session/message GET calls hit the correct AI host
// both when AI_API_BASE_URL is set and when it is absent (in which case the
// host is derived from RESUME_COACH_API_URL). No database is used.
// =============================================================================

use App\Services\ResumeCoachService;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

// ── Base URL resolution ───────────────────────────────────────────────────

test('session reads use AI_API_BASE_URL when it is set', function () {
    config(['services.ai.base_url' => 'https://ai-host.example']);
    config(['services.resume_coach.url' => 'https://coach-host.example/resume-coach-chat']);

    Http::fake([
        'https://ai-host.example/users/*/sessions' => Http::response(['status' => 'success', 'sessions' => []]),
    ]);

    (new ResumeCoachService())->listSessions('user-1');

    Http::assertSent(fn ($request) => $request->url() === 'https://ai-host.example/users/user-1/sessions');
});

test('session reads derive the host from RESUME_COACH_API_URL when AI_API_BASE_URL is absent', function () {
    // Simulate the production host that only has the existing *_API_URL vars.
    config(['services.ai.base_url' => '']);
    config(['services.resume_coach.url' => 'https://project-pr2-my-job-matching-api.onrender.com/resume-coach-chat']);

    Http::fake([
        'https://project-pr2-my-job-matching-api.onrender.com/users/*/sessions' => Http::response(['status' => 'success', 'sessions' => []]),
    ]);

    (new ResumeCoachService())->listSessions('user-1');

    Http::assertSent(fn ($request) => $request->url() === 'https://project-pr2-my-job-matching-api.onrender.com/users/user-1/sessions');
});

test('message reads derive the host from RESUME_COACH_API_URL when AI_API_BASE_URL is absent', function () {
    config(['services.ai.base_url' => '']);
    config(['services.resume_coach.url' => 'https://project-pr2-my-job-matching-api.onrender.com/resume-coach-chat']);

    Http::fake([
        'https://project-pr2-my-job-matching-api.onrender.com/sessions/*/messages' => Http::response(['status' => 'success', 'messages' => []]),
    ]);

    (new ResumeCoachService())->getSessionMessages('session-1');

    Http::assertSent(fn ($request) => $request->url() === 'https://project-pr2-my-job-matching-api.onrender.com/sessions/session-1/messages');
});
