<?php

use App\Models\Meeting;
use App\Models\User;



beforeEach(function () {
    $this->employer = User::factory()->employer()->create();
    $this->employerToken = auth('api')->login($this->employer);
    $this->seeker = User::factory()->employee()->create();
    $this->seekerToken = auth('api')->login($this->seeker);
});

afterEach(function () {
    Meeting::where('organizer_id', (string) $this->employer->_id)->delete();
    Meeting::where('invitee_id', (string) $this->seeker->_id)->delete();
    $this->employer->delete();
    $this->seeker->delete();
});

// ── Accept ────────────────────────────────────────────────────

test('invitee can accept a pending meeting', function () {
    $meeting = Meeting::create([
        'organizer_id' => (string) $this->employer->_id,
        'invitee_id' => (string) $this->seeker->_id,
        'title' => 'Interview',
        'meeting_type' => 'phone_call',
        'proposed_date' => now()->addDays(3)->format('Y-m-d'),
        'proposed_start_time' => '14:00',
        'proposed_duration_minutes' => 60,
        'status' => 'pending',
        'notes' => [],
        'previous_schedules' => [],
    ]);

    $response = $this->withToken($this->{"seekerToken"})
        ->postJson("/api/meetings/{$meeting->_id}/accept");

    $response->assertStatus(200)
        ->assertJsonPath('meeting.status', 'accepted');
});

test('invitee can accept a rescheduled meeting', function () {
    $meeting = Meeting::create([
        'organizer_id' => (string) $this->employer->_id,
        'invitee_id' => (string) $this->seeker->_id,
        'title' => 'Rescheduled Interview',
        'meeting_type' => 'phone_call',
        'proposed_date' => now()->addDays(5)->format('Y-m-d'),
        'proposed_start_time' => '10:00',
        'proposed_duration_minutes' => 45,
        'status' => 'rescheduled',
        'notes' => [],
        'previous_schedules' => [
            ['proposed_date' => now()->addDays(2)->format('Y-m-d'), 'proposed_start_time' => '09:00', 'proposed_duration_minutes' => 30],
        ],
    ]);

    $response = $this->withToken($this->{"seekerToken"})
        ->postJson("/api/meetings/{$meeting->_id}/accept");

    $response->assertStatus(200)
        ->assertJsonPath('meeting.status', 'accepted');
});

// ── Decline ───────────────────────────────────────────────────

test('invitee can decline a pending meeting with reason', function () {
    $meeting = Meeting::create([
        'organizer_id' => (string) $this->employer->_id,
        'invitee_id' => (string) $this->seeker->_id,
        'title' => 'Interview',
        'meeting_type' => 'video_call',
        'proposed_date' => now()->addDays(3)->format('Y-m-d'),
        'proposed_start_time' => '14:00',
        'proposed_duration_minutes' => 60,
        'status' => 'pending',
        'notes' => [],
        'previous_schedules' => [],
    ]);

    $response = $this->withToken($this->{"seekerToken"})
        ->postJson("/api/meetings/{$meeting->_id}/decline", [
            'decline_reason' => 'Schedule conflict',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('meeting.status', 'declined')
        ->assertJsonPath('meeting.decline_reason', 'Schedule conflict');
});

// ── Cancel ────────────────────────────────────────────────────

test('organizer can cancel a pending meeting', function () {
    $meeting = Meeting::create([
        'organizer_id' => (string) $this->employer->_id,
        'invitee_id' => (string) $this->seeker->_id,
        'title' => 'Meeting to cancel',
        'meeting_type' => 'phone_call',
        'proposed_date' => now()->addDays(3)->format('Y-m-d'),
        'proposed_start_time' => '14:00',
        'proposed_duration_minutes' => 60,
        'status' => 'pending',
        'notes' => [],
        'previous_schedules' => [],
    ]);

    $response = $this->withToken($this->{"employerToken"})
        ->postJson("/api/meetings/{$meeting->_id}/cancel");

    $response->assertStatus(200)
        ->assertJsonPath('meeting.status', 'cancelled');
});

test('invitee can cancel an accepted meeting', function () {
    $meeting = Meeting::create([
        'organizer_id' => (string) $this->employer->_id,
        'invitee_id' => (string) $this->seeker->_id,
        'title' => 'Accepted meeting',
        'meeting_type' => 'phone_call',
        'proposed_date' => now()->addDays(3)->format('Y-m-d'),
        'proposed_start_time' => '14:00',
        'proposed_duration_minutes' => 60,
        'status' => 'accepted',
        'notes' => [],
        'previous_schedules' => [],
    ]);

    $response = $this->withToken($this->{"seekerToken"})
        ->postJson("/api/meetings/{$meeting->_id}/cancel");

    $response->assertStatus(200)
        ->assertJsonPath('meeting.status', 'cancelled');
});

test('invitee cannot cancel a pending meeting must decline', function () {
    $meeting = Meeting::create([
        'organizer_id' => (string) $this->employer->_id,
        'invitee_id' => (string) $this->seeker->_id,
        'title' => 'Pending meeting',
        'meeting_type' => 'phone_call',
        'proposed_date' => now()->addDays(3)->format('Y-m-d'),
        'proposed_start_time' => '14:00',
        'proposed_duration_minutes' => 60,
        'status' => 'pending',
        'notes' => [],
        'previous_schedules' => [],
    ]);

    $response = $this->withToken($this->{"seekerToken"})
        ->postJson("/api/meetings/{$meeting->_id}/cancel");

    $response->assertStatus(422);
});

// ── Reschedule ────────────────────────────────────────────────

test('organizer can reschedule a meeting and previous_schedules is populated', function () {
    $meeting = Meeting::create([
        'organizer_id' => (string) $this->employer->_id,
        'invitee_id' => (string) $this->seeker->_id,
        'title' => 'Meeting to reschedule',
        'meeting_type' => 'video_call',
        'proposed_date' => now()->addDays(3)->format('Y-m-d'),
        'proposed_start_time' => '14:00',
        'proposed_duration_minutes' => 60,
        'status' => 'pending',
        'notes' => [],
        'previous_schedules' => [],
    ]);

    $newDate = now()->addDays(7)->format('Y-m-d');

    $response = $this->withToken($this->{"employerToken"})
        ->postJson("/api/meetings/{$meeting->_id}/reschedule", [
            'proposed_date' => $newDate,
            'proposed_start_time' => '16:00',
            'proposed_duration_minutes' => 90,
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('meeting.status', 'rescheduled')
        ->assertJsonPath('meeting.proposed_date', $newDate)
        ->assertJsonPath('meeting.proposed_start_time', '16:00');

    $updatedMeeting = Meeting::find($meeting->_id);
    expect($updatedMeeting->previous_schedules)->toHaveCount(1);
});

// ── Complete ──────────────────────────────────────────────────

test('organizer can complete an accepted past meeting', function () {
    $meeting = Meeting::create([
        'organizer_id' => (string) $this->employer->_id,
        'invitee_id' => (string) $this->seeker->_id,
        'title' => 'Past meeting',
        'meeting_type' => 'phone_call',
        'proposed_date' => now()->subDays(2)->format('Y-m-d'),
        'proposed_start_time' => '14:00',
        'proposed_duration_minutes' => 60,
        'status' => 'accepted',
        'notes' => [],
        'previous_schedules' => [],
    ]);

    $response = $this->withToken($this->{"employerToken"})
        ->postJson("/api/meetings/{$meeting->_id}/complete");

    $response->assertStatus(200)
        ->assertJsonPath('meeting.status', 'completed');
});

test('complete a future meeting returns 422', function () {
    $meeting = Meeting::create([
        'organizer_id' => (string) $this->employer->_id,
        'invitee_id' => (string) $this->seeker->_id,
        'title' => 'Future meeting',
        'meeting_type' => 'phone_call',
        'proposed_date' => now()->addDays(5)->format('Y-m-d'),
        'proposed_start_time' => '14:00',
        'proposed_duration_minutes' => 60,
        'status' => 'accepted',
        'notes' => [],
        'previous_schedules' => [],
    ]);

    $response = $this->withToken($this->{"employerToken"})
        ->postJson("/api/meetings/{$meeting->_id}/complete");

    $response->assertStatus(422)
        ->assertJsonFragment(['message' => 'This meeting has not yet occurred']);
});

// ── Authorization errors ──────────────────────────────────────

test('non-participant gets 403 on accept', function () {
    $outsider = User::factory()->employee()->create();
    $outsiderToken = auth('api')->login($outsider);

    $meeting = Meeting::create([
        'organizer_id' => (string) $this->employer->_id,
        'invitee_id' => (string) $this->seeker->_id,
        'title' => 'Private meeting',
        'meeting_type' => 'phone_call',
        'proposed_date' => now()->addDays(3)->format('Y-m-d'),
        'proposed_start_time' => '14:00',
        'proposed_duration_minutes' => 60,
        'status' => 'pending',
        'notes' => [],
        'previous_schedules' => [],
    ]);

    $response = $this->withToken($outsiderToken)
        ->postJson("/api/meetings/{$meeting->_id}/accept");

    $response->assertStatus(403);

    $outsider->delete();
});

test('non-invitee (organizer) cannot accept a meeting', function () {
    $meeting = Meeting::create([
        'organizer_id' => (string) $this->employer->_id,
        'invitee_id' => (string) $this->seeker->_id,
        'title' => 'My meeting',
        'meeting_type' => 'phone_call',
        'proposed_date' => now()->addDays(3)->format('Y-m-d'),
        'proposed_start_time' => '14:00',
        'proposed_duration_minutes' => 60,
        'status' => 'pending',
        'notes' => [],
        'previous_schedules' => [],
    ]);

    $response = $this->withToken($this->{"employerToken"})
        ->postJson("/api/meetings/{$meeting->_id}/accept");

    $response->assertStatus(403);
});

test('non-organizer cannot reschedule a meeting', function () {
    $meeting = Meeting::create([
        'organizer_id' => (string) $this->employer->_id,
        'invitee_id' => (string) $this->seeker->_id,
        'title' => 'Meeting',
        'meeting_type' => 'phone_call',
        'proposed_date' => now()->addDays(3)->format('Y-m-d'),
        'proposed_start_time' => '14:00',
        'proposed_duration_minutes' => 60,
        'status' => 'pending',
        'notes' => [],
        'previous_schedules' => [],
    ]);

    $response = $this->withToken($this->{"seekerToken"})
        ->postJson("/api/meetings/{$meeting->_id}/reschedule", [
            'proposed_date' => now()->addDays(10)->format('Y-m-d'),
            'proposed_start_time' => '15:00',
            'proposed_duration_minutes' => 60,
        ]);

    $response->assertStatus(403);
});

