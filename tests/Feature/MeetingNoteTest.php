<?php

use App\Models\Meeting;
use App\Models\User;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->employer = User::factory()->employer()->create();
    $this->seeker = User::factory()->employee()->create();
    $this->meeting = Meeting::create([
        'organizer_id' => (string) $this->employer->_id,
        'invitee_id' => (string) $this->seeker->_id,
        'title' => 'Note Test Meeting',
        'meeting_type' => 'video_call',
        'proposed_date' => now()->addDays(3)->format('Y-m-d'),
        'proposed_start_time' => '10:00',
        'proposed_duration_minutes' => 60,
        'status' => 'accepted',
        'notes' => [],
        'previous_schedules' => [],
    ]);
});

afterEach(function () {
    $this->meeting->delete();
    $this->employer->delete();
    $this->seeker->delete();
});

test('participant can add a note', function () {
    $response = $this->actingAs($this->employer, 'api')
        ->postJson("/api/meetings/{$this->meeting->_id}/notes", [
            'content' => 'Please prepare your portfolio.',
        ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['meeting' => ['notes']]);

    $notes = $response->json('meeting.notes');
    expect($notes)->toHaveCount(1);
    expect($notes[0]['content'])->toBe('Please prepare your portfolio.');
    expect($notes[0]['author_id'])->toBe((string) $this->employer->_id);
});

test('non-participant gets 403', function () {
    $outsider = User::factory()->employee()->create();

    $response = $this->actingAs($outsider, 'api')
        ->postJson("/api/meetings/{$this->meeting->_id}/notes", [
            'content' => 'Trying to sneak a note in.',
        ]);

    $response->assertStatus(403);

    $outsider->delete();
});

test('empty content returns 422', function () {
    $response = $this->actingAs($this->employer, 'api')
        ->postJson("/api/meetings/{$this->meeting->_id}/notes", [
            'content' => '',
        ]);

    $response->assertStatus(422);
});

test('whitespace-only content returns 422', function () {
    $response = $this->actingAs($this->employer, 'api')
        ->postJson("/api/meetings/{$this->meeting->_id}/notes", [
            'content' => '   ',
        ]);

    $response->assertStatus(422);
});

test('content exceeding 2000 chars returns 422', function () {
    $longContent = str_repeat('a', 2001);

    $response = $this->actingAs($this->employer, 'api')
        ->postJson("/api/meetings/{$this->meeting->_id}/notes", [
            'content' => $longContent,
        ]);

    $response->assertStatus(422);
});

test('meeting not found returns 404', function () {
    $response = $this->actingAs($this->employer, 'api')
        ->postJson('/api/meetings/000000000000000000000000/notes', [
            'content' => 'Note for nonexistent meeting.',
        ]);

    $response->assertStatus(404);
});
