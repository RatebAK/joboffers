<?php

// =============================================================================
// MeetingCreateTest — POST /api/meetings
// =============================================================================

use App\Models\User;

beforeEach(function () {
    [$this->employer, $this->employerToken] = userWithToken('employer');
    [$this->seeker, $this->seekerToken]     = userWithToken('employee');
});

/** A valid create payload inviting the seeker, with overrides. */
function meetingPayload(User $invitee, array $overrides = []): array
{
    return array_merge([
        'invitee_id'                => (string) $invitee->_id,
        'title'                     => 'Initial Interview',
        'meeting_type'              => 'video_call',
        'proposed_date'             => now()->addDays(3)->format('Y-m-d'),
        'proposed_start_time'       => '14:00',
        'proposed_duration_minutes' => 60,
    ], $overrides);
}

test('an employer can invite a job seeker to a meeting', function () {
    $this->withToken($this->employerToken)
        ->postJson('/api/meetings', meetingPayload($this->seeker))
        ->assertCreated()
        ->assertJsonPath('meeting.status', 'pending')
        ->assertJsonPath('meeting.title', 'Initial Interview')
        ->assertJsonStructure([
            'message',
            'meeting' => ['organizer_id', 'invitee_id', 'title', 'status'],
            'organizer_conflicts',
            'invitee_conflicts',
        ]);
});

test('a job seeker can invite an employer to a meeting', function () {
    $this->withToken($this->seekerToken)
        ->postJson('/api/meetings', meetingPayload($this->employer, [
            'meeting_type'     => 'phone_call',
            'location_or_link' => '+1-555-0100',
        ]))
        ->assertCreated()
        ->assertJsonPath('meeting.status', 'pending');
});

test('creating a meeting requires all mandatory fields', function () {
    $this->withToken($this->employerToken)
        ->postJson('/api/meetings', [])
        ->assertStatus(422)
        ->assertJsonStructure(['invitee_id', 'title', 'meeting_type', 'proposed_date', 'proposed_start_time', 'proposed_duration_minutes']);
});

test('an invalid meeting_type is rejected', function () {
    $this->withToken($this->employerToken)
        ->postJson('/api/meetings', meetingPayload($this->seeker, ['meeting_type' => 'invalid_type']))
        ->assertStatus(422)
        ->assertJsonStructure(['meeting_type']);
});

test('a past proposed_date is rejected', function () {
    $this->withToken($this->employerToken)
        ->postJson('/api/meetings', meetingPayload($this->seeker, ['proposed_date' => now()->subDays(2)->format('Y-m-d')]))
        ->assertStatus(422)
        ->assertJsonStructure(['proposed_date']);
});

test('a user cannot invite themselves', function () {
    $this->withToken($this->employerToken)
        ->postJson('/api/meetings', meetingPayload($this->employer))
        ->assertStatus(422);
});

test('an employer cannot invite another employer', function () {
    $otherEmployer = createUser('employer');

    $this->withToken($this->employerToken)
        ->postJson('/api/meetings', meetingPayload($otherEmployer))
        ->assertStatus(422);
});

test('inviting a non-existent user is rejected', function () {
    $this->withToken($this->employerToken)
        ->postJson('/api/meetings', meetingPayload($this->seeker, ['invitee_id' => '000000000000000000000000']))
        ->assertStatus(422);
});

test('an overlapping meeting surfaces conflict warnings', function () {
    createMeeting($this->employer, $this->seeker, [
        'meeting_type'        => 'phone_call',
        'proposed_start_time' => '14:00',
        'status'              => 'accepted',
    ]);

    $response = $this->withToken($this->employerToken)
        ->postJson('/api/meetings', meetingPayload($this->seeker, [
            'title'               => 'Overlapping Meeting',
            'proposed_start_time' => '14:30',
        ]))
        ->assertCreated()
        ->assertJsonStructure(['organizer_conflicts', 'invitee_conflicts']);

    expect(count($response->json('organizer_conflicts')))->toBeGreaterThanOrEqual(1);
});
