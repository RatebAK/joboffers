<?php

use App\Models\Meeting;
use App\Models\User;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->employer = User::factory()->employer()->create();
    $this->seeker = User::factory()->employee()->create();
});

afterEach(function () {
    Meeting::where('organizer_id', (string) $this->employer->_id)->delete();
    Meeting::where('invitee_id', (string) $this->seeker->_id)->delete();
    $this->employer->delete();
    $this->seeker->delete();
});

test('employer can create a meeting with a job seeker', function () {
    $response = $this->actingAs($this->employer, 'api')
        ->postJson('/api/meetings', [
            'invitee_id' => (string) $this->seeker->_id,
            'title' => 'Initial Interview',
            'meeting_type' => 'video_call',
            'proposed_date' => now()->addDays(3)->format('Y-m-d'),
            'proposed_start_time' => '14:00',
            'proposed_duration_minutes' => 60,
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('meeting.status', 'pending')
        ->assertJsonPath('meeting.title', 'Initial Interview')
        ->assertJsonPath('meeting.meeting_type', 'video_call')
        ->assertJsonStructure([
            'message',
            'meeting' => ['organizer_id', 'invitee_id', 'title', 'status'],
            'organizer_conflicts',
            'invitee_conflicts',
        ]);
});

test('job seeker can create a meeting with an employer', function () {
    $response = $this->actingAs($this->seeker, 'api')
        ->postJson('/api/meetings', [
            'invitee_id' => (string) $this->employer->_id,
            'title' => 'Discussion about opportunity',
            'meeting_type' => 'phone_call',
            'proposed_date' => now()->addDays(5)->format('Y-m-d'),
            'proposed_start_time' => '10:00',
            'proposed_duration_minutes' => 30,
            'location_or_link' => '+1-555-0100',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('meeting.status', 'pending');
});

test('missing required fields returns 422 with validation errors', function () {
    $response = $this->actingAs($this->employer, 'api')
        ->postJson('/api/meetings', []);

    $response->assertStatus(422)
        ->assertJsonStructure(['errors' => ['invitee_id', 'title', 'meeting_type', 'proposed_date', 'proposed_start_time', 'proposed_duration_minutes']]);
});

test('invalid meeting_type returns 422', function () {
    $response = $this->actingAs($this->employer, 'api')
        ->postJson('/api/meetings', [
            'invitee_id' => (string) $this->seeker->_id,
            'title' => 'Test Meeting',
            'meeting_type' => 'invalid_type',
            'proposed_date' => now()->addDays(3)->format('Y-m-d'),
            'proposed_start_time' => '14:00',
            'proposed_duration_minutes' => 60,
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['meeting_type']);
});

test('past proposed_date returns 422', function () {
    $response = $this->actingAs($this->employer, 'api')
        ->postJson('/api/meetings', [
            'invitee_id' => (string) $this->seeker->_id,
            'title' => 'Test Meeting',
            'meeting_type' => 'video_call',
            'proposed_date' => now()->subDays(2)->format('Y-m-d'),
            'proposed_start_time' => '14:00',
            'proposed_duration_minutes' => 60,
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['proposed_date']);
});

test('self-invite returns 422', function () {
    $response = $this->actingAs($this->employer, 'api')
        ->postJson('/api/meetings', [
            'invitee_id' => (string) $this->employer->_id,
            'title' => 'Test Meeting',
            'meeting_type' => 'video_call',
            'proposed_date' => now()->addDays(3)->format('Y-m-d'),
            'proposed_start_time' => '14:00',
            'proposed_duration_minutes' => 60,
        ]);

    $response->assertStatus(422);
});

test('employer inviting another employer returns 422', function () {
    $otherEmployer = User::factory()->employer()->create();

    $response = $this->actingAs($this->employer, 'api')
        ->postJson('/api/meetings', [
            'invitee_id' => (string) $otherEmployer->_id,
            'title' => 'Test Meeting',
            'meeting_type' => 'video_call',
            'proposed_date' => now()->addDays(3)->format('Y-m-d'),
            'proposed_start_time' => '14:00',
            'proposed_duration_minutes' => 60,
        ]);

    $response->assertStatus(422);

    $otherEmployer->delete();
});

test('invitee not found returns 422', function () {
    $response = $this->actingAs($this->employer, 'api')
        ->postJson('/api/meetings', [
            'invitee_id' => '000000000000000000000000',
            'title' => 'Test Meeting',
            'meeting_type' => 'video_call',
            'proposed_date' => now()->addDays(3)->format('Y-m-d'),
            'proposed_start_time' => '14:00',
            'proposed_duration_minutes' => 60,
        ]);

    $response->assertStatus(422);
});

test('conflict warnings included in response when overlapping meeting exists', function () {
    // Create an existing accepted meeting that overlaps
    Meeting::create([
        'organizer_id' => (string) $this->employer->_id,
        'invitee_id' => (string) $this->seeker->_id,
        'title' => 'Existing Meeting',
        'meeting_type' => 'phone_call',
        'proposed_date' => now()->addDays(3)->format('Y-m-d'),
        'proposed_start_time' => '14:00',
        'proposed_duration_minutes' => 60,
        'status' => 'accepted',
        'notes' => [],
        'previous_schedules' => [],
    ]);

    $response = $this->actingAs($this->employer, 'api')
        ->postJson('/api/meetings', [
            'invitee_id' => (string) $this->seeker->_id,
            'title' => 'Overlapping Meeting',
            'meeting_type' => 'video_call',
            'proposed_date' => now()->addDays(3)->format('Y-m-d'),
            'proposed_start_time' => '14:30',
            'proposed_duration_minutes' => 60,
        ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['organizer_conflicts', 'invitee_conflicts']);

    // At least one conflict should be detected for the organizer
    $data = $response->json();
    expect(count($data['organizer_conflicts']))->toBeGreaterThanOrEqual(1);
});
