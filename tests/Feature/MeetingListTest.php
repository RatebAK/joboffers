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

test('returns paginated list with correct format', function () {
    Meeting::create([
        'organizer_id' => (string) $this->employer->_id,
        'invitee_id' => (string) $this->seeker->_id,
        'title' => 'Test Meeting',
        'meeting_type' => 'video_call',
        'proposed_date' => now()->addDays(3)->format('Y-m-d'),
        'proposed_start_time' => '10:00',
        'proposed_duration_minutes' => 60,
        'status' => 'pending',
        'notes' => [],
        'previous_schedules' => [],
    ]);

    $response = $this->actingAs($this->employer, 'api')
        ->getJson('/api/meetings');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data',
            'current_page',
            'per_page',
            'total',
            'total_pages',
            'next_page',
            'prev_page',
        ]);

    expect($response->json('current_page'))->toBe(1);
    expect($response->json('per_page'))->toBe(15);
});

test('filters by status', function () {
    Meeting::create([
        'organizer_id' => (string) $this->employer->_id,
        'invitee_id' => (string) $this->seeker->_id,
        'title' => 'Pending Meeting',
        'meeting_type' => 'video_call',
        'proposed_date' => now()->addDays(3)->format('Y-m-d'),
        'proposed_start_time' => '10:00',
        'proposed_duration_minutes' => 60,
        'status' => 'pending',
        'notes' => [],
        'previous_schedules' => [],
    ]);

    Meeting::create([
        'organizer_id' => (string) $this->employer->_id,
        'invitee_id' => (string) $this->seeker->_id,
        'title' => 'Accepted Meeting',
        'meeting_type' => 'phone_call',
        'proposed_date' => now()->addDays(5)->format('Y-m-d'),
        'proposed_start_time' => '11:00',
        'proposed_duration_minutes' => 30,
        'status' => 'accepted',
        'notes' => [],
        'previous_schedules' => [],
    ]);

    $response = $this->actingAs($this->employer, 'api')
        ->getJson('/api/meetings?status=pending');

    $response->assertStatus(200);
    $data = $response->json('data');
    foreach ($data as $meeting) {
        expect($meeting['status'])->toBe('pending');
    }
});

test('filters by date range', function () {
    $futureDate = now()->addDays(10)->format('Y-m-d');

    Meeting::create([
        'organizer_id' => (string) $this->employer->_id,
        'invitee_id' => (string) $this->seeker->_id,
        'title' => 'In Range Meeting',
        'meeting_type' => 'video_call',
        'proposed_date' => $futureDate,
        'proposed_start_time' => '10:00',
        'proposed_duration_minutes' => 60,
        'status' => 'pending',
        'notes' => [],
        'previous_schedules' => [],
    ]);

    $fromDate = now()->addDays(8)->format('Y-m-d');
    $toDate = now()->addDays(12)->format('Y-m-d');

    $response = $this->actingAs($this->employer, 'api')
        ->getJson("/api/meetings?from_date={$fromDate}&to_date={$toDate}");

    $response->assertStatus(200);
    expect($response->json('total'))->toBeGreaterThanOrEqual(1);
});

test('sort direction works desc', function () {
    Meeting::create([
        'organizer_id' => (string) $this->employer->_id,
        'invitee_id' => (string) $this->seeker->_id,
        'title' => 'Earlier Meeting',
        'meeting_type' => 'video_call',
        'proposed_date' => now()->addDays(3)->format('Y-m-d'),
        'proposed_start_time' => '10:00',
        'proposed_duration_minutes' => 60,
        'status' => 'pending',
        'notes' => [],
        'previous_schedules' => [],
    ]);

    Meeting::create([
        'organizer_id' => (string) $this->employer->_id,
        'invitee_id' => (string) $this->seeker->_id,
        'title' => 'Later Meeting',
        'meeting_type' => 'phone_call',
        'proposed_date' => now()->addDays(10)->format('Y-m-d'),
        'proposed_start_time' => '11:00',
        'proposed_duration_minutes' => 30,
        'status' => 'pending',
        'notes' => [],
        'previous_schedules' => [],
    ]);

    $response = $this->actingAs($this->employer, 'api')
        ->getJson('/api/meetings?sort_direction=desc');

    $response->assertStatus(200);
    $data = $response->json('data');
    if (count($data) >= 2) {
        expect($data[0]['proposed_date'])->toBeGreaterThanOrEqual($data[1]['proposed_date']);
    }
});

test('returns empty data array when no matches', function () {
    $response = $this->actingAs($this->employer, 'api')
        ->getJson('/api/meetings?status=completed');

    $response->assertStatus(200)
        ->assertJsonPath('data', [])
        ->assertJsonPath('total', 0);
});

test('includes participant info in list', function () {
    Meeting::create([
        'organizer_id' => (string) $this->employer->_id,
        'invitee_id' => (string) $this->seeker->_id,
        'title' => 'Meeting with participant',
        'meeting_type' => 'video_call',
        'proposed_date' => now()->addDays(3)->format('Y-m-d'),
        'proposed_start_time' => '10:00',
        'proposed_duration_minutes' => 60,
        'status' => 'pending',
        'notes' => [],
        'previous_schedules' => [],
    ]);

    $response = $this->actingAs($this->employer, 'api')
        ->getJson('/api/meetings');

    $response->assertStatus(200);
    $data = $response->json('data');
    expect($data[0])->toHaveKey('participant');
    expect($data[0]['participant'])->toHaveKeys(['name', 'email']);
});

test('upcoming endpoint returns max 5 accepted future meetings', function () {
    // Create 6 accepted future meetings
    for ($i = 1; $i <= 6; $i++) {
        Meeting::create([
            'organizer_id' => (string) $this->employer->_id,
            'invitee_id' => (string) $this->seeker->_id,
            'title' => "Upcoming Meeting {$i}",
            'meeting_type' => 'video_call',
            'proposed_date' => now()->addDays($i + 1)->format('Y-m-d'),
            'proposed_start_time' => '10:00',
            'proposed_duration_minutes' => 30,
            'status' => 'accepted',
            'notes' => [],
            'previous_schedules' => [],
        ]);
    }

    $response = $this->actingAs($this->employer, 'api')
        ->getJson('/api/meetings/upcoming');

    $response->assertStatus(200)
        ->assertJsonStructure(['meetings']);

    $meetings = $response->json('meetings');
    expect(count($meetings))->toBeLessThanOrEqual(5);
});

test('unauthorized request returns 401', function () {
    $response = $this->getJson('/api/meetings');

    $response->assertStatus(401);
});
