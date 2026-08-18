<?php

use App\Models\Meeting;
use App\Models\User;



beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->adminToken = auth('api')->login($this->admin);
    $this->employer = User::factory()->employer()->create();
    $this->employerToken = auth('api')->login($this->employer);
    $this->seeker = User::factory()->employee()->create();
    $this->seekerToken = auth('api')->login($this->seeker);
});

afterEach(function () {
    Meeting::where('organizer_id', (string) $this->employer->_id)->delete();
    $this->admin->delete();
    $this->employer->delete();
    $this->seeker->delete();
});

test('admin can list all meetings regardless of ownership', function () {
    Meeting::create([
        'organizer_id' => (string) $this->employer->_id,
        'invitee_id' => (string) $this->seeker->_id,
        'title' => 'Other peoples meeting',
        'meeting_type' => 'video_call',
        'proposed_date' => now()->addDays(3)->format('Y-m-d'),
        'proposed_start_time' => '10:00',
        'proposed_duration_minutes' => 60,
        'status' => 'pending',
        'notes' => [],
        'previous_schedules' => [],
    ]);

    $response = $this->withToken($this->{"adminToken"})
        ->getJson('/api/admin/meetings');

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

    expect($response->json('total'))->toBeGreaterThanOrEqual(1);
});

test('admin can view any meeting by ID', function () {
    $meeting = Meeting::create([
        'organizer_id' => (string) $this->employer->_id,
        'invitee_id' => (string) $this->seeker->_id,
        'title' => 'Admin viewable meeting',
        'meeting_type' => 'phone_call',
        'proposed_date' => now()->addDays(5)->format('Y-m-d'),
        'proposed_start_time' => '11:00',
        'proposed_duration_minutes' => 45,
        'status' => 'accepted',
        'notes' => [],
        'previous_schedules' => [],
    ]);

    $response = $this->withToken($this->{"adminToken"})
        ->getJson("/api/admin/meetings/{$meeting->_id}");

    $response->assertStatus(200)
        ->assertJsonStructure(['meeting'])
        ->assertJsonPath('meeting.title', 'Admin viewable meeting');
});

test('non-admin gets 403 on admin meeting list endpoint', function () {
    $response = $this->withToken($this->{"employerToken"})
        ->getJson('/api/admin/meetings');

    $response->assertStatus(403);
});

test('non-admin gets 403 on admin meeting show endpoint', function () {
    $meeting = Meeting::create([
        'organizer_id' => (string) $this->employer->_id,
        'invitee_id' => (string) $this->seeker->_id,
        'title' => 'Cannot view as employer',
        'meeting_type' => 'video_call',
        'proposed_date' => now()->addDays(3)->format('Y-m-d'),
        'proposed_start_time' => '10:00',
        'proposed_duration_minutes' => 60,
        'status' => 'pending',
        'notes' => [],
        'previous_schedules' => [],
    ]);

    $response = $this->withToken($this->{"seekerToken"})
        ->getJson("/api/admin/meetings/{$meeting->_id}");

    $response->assertStatus(403);
});

test('admin gets 404 for non-existent meeting ID', function () {
    $response = $this->withToken($this->{"adminToken"})
        ->getJson('/api/admin/meetings/000000000000000000000000');

    $response->assertStatus(404);
});

