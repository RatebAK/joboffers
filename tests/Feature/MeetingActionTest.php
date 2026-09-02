<?php

// =============================================================================
// MeetingActionTest — accept / decline / cancel / reschedule / complete
// =============================================================================

use App\Models\Meeting;

beforeEach(function () {
    [$this->employer, $this->employerToken] = userWithToken('employer');
    [$this->seeker, $this->seekerToken]     = userWithToken('employee');
});

// ── Accept ─────────────────────────────────────────────────────────────

test('the invitee can accept a pending meeting', function () {
    $meeting = createMeeting($this->employer, $this->seeker);

    $this->withToken($this->seekerToken)
        ->postJson("/api/meetings/{$meeting->_id}/accept")
        ->assertOk()
        ->assertJsonPath('meeting.status', 'accepted');
});

test('the invitee can accept a rescheduled meeting', function () {
    $meeting = createMeeting($this->employer, $this->seeker, [
        'status'             => 'rescheduled',
        'previous_schedules' => [
            ['proposed_date' => now()->addDays(2)->format('Y-m-d'), 'proposed_start_time' => '09:00', 'proposed_duration_minutes' => 30],
        ],
    ]);

    $this->withToken($this->seekerToken)
        ->postJson("/api/meetings/{$meeting->_id}/accept")
        ->assertOk()
        ->assertJsonPath('meeting.status', 'accepted');
});

// ── Decline ────────────────────────────────────────────────────────────

test('the invitee can decline a pending meeting with a reason', function () {
    $meeting = createMeeting($this->employer, $this->seeker);

    $this->withToken($this->seekerToken)
        ->postJson("/api/meetings/{$meeting->_id}/decline", ['decline_reason' => 'Schedule conflict'])
        ->assertOk()
        ->assertJsonPath('meeting.status', 'declined')
        ->assertJsonPath('meeting.decline_reason', 'Schedule conflict');
});

// ── Cancel ─────────────────────────────────────────────────────────────

test('the organizer can cancel a pending meeting', function () {
    $meeting = createMeeting($this->employer, $this->seeker);

    $this->withToken($this->employerToken)
        ->postJson("/api/meetings/{$meeting->_id}/cancel")
        ->assertOk()
        ->assertJsonPath('meeting.status', 'cancelled');
});

test('the invitee can cancel an accepted meeting', function () {
    $meeting = createMeeting($this->employer, $this->seeker, ['status' => 'accepted']);

    $this->withToken($this->seekerToken)
        ->postJson("/api/meetings/{$meeting->_id}/cancel")
        ->assertOk()
        ->assertJsonPath('meeting.status', 'cancelled');
});

test('the invitee cannot cancel a pending meeting (must decline instead)', function () {
    $meeting = createMeeting($this->employer, $this->seeker);

    $this->withToken($this->seekerToken)
        ->postJson("/api/meetings/{$meeting->_id}/cancel")
        ->assertStatus(422);
});

// ── Reschedule ─────────────────────────────────────────────────────────

test('the organizer can reschedule a meeting, recording the previous schedule', function () {
    $meeting = createMeeting($this->employer, $this->seeker);
    $newDate = now()->addDays(7)->format('Y-m-d');

    $this->withToken($this->employerToken)
        ->postJson("/api/meetings/{$meeting->_id}/reschedule", [
            'proposed_date'             => $newDate,
            'proposed_start_time'       => '16:00',
            'proposed_duration_minutes' => 90,
        ])
        ->assertOk()
        ->assertJsonPath('meeting.status', 'rescheduled')
        ->assertJsonPath('meeting.proposed_date', $newDate)
        ->assertJsonPath('meeting.proposed_start_time', '16:00');

    expect(Meeting::find($meeting->_id)->previous_schedules)->toHaveCount(1);
});

// ── Complete ───────────────────────────────────────────────────────────

test('the organizer can complete an accepted past meeting', function () {
    $meeting = createMeeting($this->employer, $this->seeker, [
        'status'        => 'accepted',
        'proposed_date' => now()->subDays(2)->format('Y-m-d'),
    ]);

    $this->withToken($this->employerToken)
        ->postJson("/api/meetings/{$meeting->_id}/complete")
        ->assertOk()
        ->assertJsonPath('meeting.status', 'completed');
});

test('completing a future meeting is rejected', function () {
    $meeting = createMeeting($this->employer, $this->seeker, [
        'status'        => 'accepted',
        'proposed_date' => now()->addDays(5)->format('Y-m-d'),
    ]);

    $this->withToken($this->employerToken)
        ->postJson("/api/meetings/{$meeting->_id}/complete")
        ->assertStatus(422)
        ->assertJsonFragment(['message' => 'This meeting has not yet occurred']);
});

// ── Authorization ──────────────────────────────────────────────────────

test('a non-participant cannot accept a meeting', function () {
    $meeting = createMeeting($this->employer, $this->seeker);
    $outsiderToken = tokenFor('employee');

    $this->withToken($outsiderToken)
        ->postJson("/api/meetings/{$meeting->_id}/accept")
        ->assertForbidden();
});

test('the organizer cannot accept their own meeting invitation', function () {
    $meeting = createMeeting($this->employer, $this->seeker);

    $this->withToken($this->employerToken)
        ->postJson("/api/meetings/{$meeting->_id}/accept")
        ->assertForbidden();
});

test('a non-organizer cannot reschedule a meeting', function () {
    $meeting = createMeeting($this->employer, $this->seeker);

    $this->withToken($this->seekerToken)
        ->postJson("/api/meetings/{$meeting->_id}/reschedule", [
            'proposed_date'             => now()->addDays(10)->format('Y-m-d'),
            'proposed_start_time'       => '15:00',
            'proposed_duration_minutes' => 60,
        ])
        ->assertForbidden();
});
