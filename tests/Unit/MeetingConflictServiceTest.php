<?php

use App\Models\Meeting;
use App\Models\User;
use App\Services\MeetingConflictService;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->conflictService = new MeetingConflictService();

    $this->employer = User::factory()->employer()->create();
    $this->seeker = User::factory()->employee()->create();
});

afterEach(function () {
    Meeting::where('organizer_id', (string) $this->employer->_id)->delete();
    Meeting::where('invitee_id', (string) $this->seeker->_id)->delete();
    $this->employer->delete();
    $this->seeker->delete();
});

test('overlapping meetings are detected as conflicts', function () {
    // Existing meeting: 10:00 - 11:00 (60 min)
    Meeting::create([
        'organizer_id' => (string) $this->employer->_id,
        'invitee_id' => (string) $this->seeker->_id,
        'title' => 'Existing Meeting',
        'meeting_type' => 'video_call',
        'proposed_date' => '2025-08-15',
        'proposed_start_time' => '10:00',
        'proposed_duration_minutes' => 60,
        'status' => 'accepted',
    ]);

    // New meeting: 10:30 - 11:30 (60 min) — overlaps with existing
    $conflicts = $this->conflictService->detectConflicts(
        (string) $this->employer->_id,
        '2025-08-15',
        '10:30',
        60
    );

    expect($conflicts)->toHaveCount(1);
    expect($conflicts[0]['proposed_start_time'])->toBe('10:00');
    expect($conflicts[0]['proposed_duration_minutes'])->toBe(60);
});

test('adjacent meetings where one ends exactly when other starts are NOT conflicts', function () {
    // Existing meeting: 10:00 - 11:00 (60 min)
    Meeting::create([
        'organizer_id' => (string) $this->employer->_id,
        'invitee_id' => (string) $this->seeker->_id,
        'title' => 'First Meeting',
        'meeting_type' => 'phone_call',
        'proposed_date' => '2025-08-15',
        'proposed_start_time' => '10:00',
        'proposed_duration_minutes' => 60,
        'status' => 'accepted',
    ]);

    // New meeting starts exactly at 11:00 — adjacent, no overlap
    $conflicts = $this->conflictService->detectConflicts(
        (string) $this->employer->_id,
        '2025-08-15',
        '11:00',
        30
    );

    expect($conflicts)->toBeEmpty();
});

test('same exact time is a conflict', function () {
    // Existing meeting: 14:00 - 15:00 (60 min)
    Meeting::create([
        'organizer_id' => (string) $this->employer->_id,
        'invitee_id' => (string) $this->seeker->_id,
        'title' => 'Afternoon Meeting',
        'meeting_type' => 'in_person',
        'proposed_date' => '2025-08-15',
        'proposed_start_time' => '14:00',
        'proposed_duration_minutes' => 60,
        'status' => 'accepted',
    ]);

    // New meeting: same exact time slot (14:00, 60 min)
    $conflicts = $this->conflictService->detectConflicts(
        (string) $this->employer->_id,
        '2025-08-15',
        '14:00',
        60
    );

    expect($conflicts)->toHaveCount(1);
    expect($conflicts[0]['proposed_date'])->toBe('2025-08-15');
    expect($conflicts[0]['proposed_start_time'])->toBe('14:00');
});

test('partial overlap is detected', function () {
    // Existing meeting: 09:00 - 10:30 (90 min)
    Meeting::create([
        'organizer_id' => (string) $this->employer->_id,
        'invitee_id' => (string) $this->seeker->_id,
        'title' => 'Morning Meeting',
        'meeting_type' => 'video_call',
        'proposed_date' => '2025-08-15',
        'proposed_start_time' => '09:00',
        'proposed_duration_minutes' => 90,
        'status' => 'accepted',
    ]);

    // New meeting: 10:00 - 10:30 (30 min) — starts during existing meeting
    $conflicts = $this->conflictService->detectConflicts(
        (string) $this->employer->_id,
        '2025-08-15',
        '10:00',
        30
    );

    expect($conflicts)->toHaveCount(1);
    expect($conflicts[0]['proposed_start_time'])->toBe('09:00');
});

test('excludeMeetingId excludes the specified meeting from conflict detection', function () {
    // Existing meeting we want to exclude (simulating reschedule)
    $existingMeeting = Meeting::create([
        'organizer_id' => (string) $this->employer->_id,
        'invitee_id' => (string) $this->seeker->_id,
        'title' => 'Meeting to Reschedule',
        'meeting_type' => 'phone_call',
        'proposed_date' => '2025-08-15',
        'proposed_start_time' => '14:00',
        'proposed_duration_minutes' => 60,
        'status' => 'accepted',
    ]);

    // Detect conflicts for the same time, excluding the meeting itself
    $conflicts = $this->conflictService->detectConflicts(
        (string) $this->employer->_id,
        '2025-08-15',
        '14:00',
        60,
        (string) $existingMeeting->_id
    );

    expect($conflicts)->toBeEmpty();
});

test('meetings on different dates do not conflict', function () {
    // Existing meeting on Aug 15
    Meeting::create([
        'organizer_id' => (string) $this->employer->_id,
        'invitee_id' => (string) $this->seeker->_id,
        'title' => 'Aug 15 Meeting',
        'meeting_type' => 'video_call',
        'proposed_date' => '2025-08-15',
        'proposed_start_time' => '10:00',
        'proposed_duration_minutes' => 60,
        'status' => 'accepted',
    ]);

    // Check conflicts for Aug 16 at the same time — no overlap
    $conflicts = $this->conflictService->detectConflicts(
        (string) $this->employer->_id,
        '2025-08-16',
        '10:00',
        60
    );

    expect($conflicts)->toBeEmpty();
});

test('only accepted meetings are considered for conflicts', function () {
    // Pending meeting — should NOT be considered
    Meeting::create([
        'organizer_id' => (string) $this->employer->_id,
        'invitee_id' => (string) $this->seeker->_id,
        'title' => 'Pending Meeting',
        'meeting_type' => 'video_call',
        'proposed_date' => '2025-08-15',
        'proposed_start_time' => '10:00',
        'proposed_duration_minutes' => 60,
        'status' => 'pending',
    ]);

    // Cancelled meeting — should NOT be considered
    Meeting::create([
        'organizer_id' => (string) $this->employer->_id,
        'invitee_id' => (string) $this->seeker->_id,
        'title' => 'Cancelled Meeting',
        'meeting_type' => 'video_call',
        'proposed_date' => '2025-08-15',
        'proposed_start_time' => '10:00',
        'proposed_duration_minutes' => 60,
        'status' => 'cancelled',
    ]);

    $conflicts = $this->conflictService->detectConflicts(
        (string) $this->employer->_id,
        '2025-08-15',
        '10:00',
        60
    );

    expect($conflicts)->toBeEmpty();
});

test('conflicts detected when user is invitee', function () {
    // Existing meeting where our seeker is the invitee
    Meeting::create([
        'organizer_id' => (string) $this->employer->_id,
        'invitee_id' => (string) $this->seeker->_id,
        'title' => 'Meeting as Invitee',
        'meeting_type' => 'video_call',
        'proposed_date' => '2025-08-15',
        'proposed_start_time' => '10:00',
        'proposed_duration_minutes' => 60,
        'status' => 'accepted',
    ]);

    // Check conflicts for the seeker (invitee)
    $conflicts = $this->conflictService->detectConflicts(
        (string) $this->seeker->_id,
        '2025-08-15',
        '10:30',
        60
    );

    expect($conflicts)->toHaveCount(1);
});
