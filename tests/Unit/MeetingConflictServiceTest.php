<?php

// =============================================================================
// MeetingConflictServiceTest — unit tests for MeetingConflictService.
//
// Verifies overlap detection: overlapping/adjacent/identical slots, the
// exclude-self case (reschedule), date isolation, status filtering, and that
// a user is checked both as organizer and invitee.
// =============================================================================

use App\Services\MeetingConflictService;
use Tests\Concerns\RefreshMongoDatabase;

uses(Tests\TestCase::class, RefreshMongoDatabase::class);

beforeEach(function () {
    $this->setUpRefreshMongoDatabase();
    $this->service  = new MeetingConflictService();
    $this->employer = createUser('employer');
    $this->seeker   = createUser('employee');
});

/** An accepted meeting on 2025-08-15 by default. */
function acceptedMeeting(array $overrides = []): \App\Models\Meeting
{
    return createMeeting(test()->employer, test()->seeker, array_merge([
        'status'              => 'accepted',
        'proposed_date'       => '2025-08-15',
        'proposed_start_time' => '10:00',
    ], $overrides));
}

test('overlapping meetings are detected as conflicts', function () {
    acceptedMeeting(['proposed_start_time' => '10:00', 'proposed_duration_minutes' => 60]);

    $conflicts = $this->service->detectConflicts((string) $this->employer->_id, '2025-08-15', '10:30', 60);

    expect($conflicts)->toHaveCount(1)
        ->and($conflicts[0]['proposed_start_time'])->toBe('10:00')
        ->and($conflicts[0]['proposed_duration_minutes'])->toBe(60);
});

test('adjacent meetings (one ends exactly when the next starts) are not conflicts', function () {
    acceptedMeeting(['proposed_start_time' => '10:00', 'proposed_duration_minutes' => 60]);

    $conflicts = $this->service->detectConflicts((string) $this->employer->_id, '2025-08-15', '11:00', 30);

    expect($conflicts)->toBeEmpty();
});

test('an identical time slot is a conflict', function () {
    acceptedMeeting(['proposed_start_time' => '14:00', 'proposed_duration_minutes' => 60]);

    $conflicts = $this->service->detectConflicts((string) $this->employer->_id, '2025-08-15', '14:00', 60);

    expect($conflicts)->toHaveCount(1)
        ->and($conflicts[0]['proposed_start_time'])->toBe('14:00');
});

test('a partial overlap is detected', function () {
    acceptedMeeting(['proposed_start_time' => '09:00', 'proposed_duration_minutes' => 90]);

    $conflicts = $this->service->detectConflicts((string) $this->employer->_id, '2025-08-15', '10:00', 30);

    expect($conflicts)->toHaveCount(1)
        ->and($conflicts[0]['proposed_start_time'])->toBe('09:00');
});

test('the excluded meeting id is ignored (reschedule case)', function () {
    $meeting = acceptedMeeting(['proposed_start_time' => '14:00', 'proposed_duration_minutes' => 60]);

    $conflicts = $this->service->detectConflicts((string) $this->employer->_id, '2025-08-15', '14:00', 60, (string) $meeting->_id);

    expect($conflicts)->toBeEmpty();
});

test('meetings on different dates do not conflict', function () {
    acceptedMeeting(['proposed_date' => '2025-08-15', 'proposed_start_time' => '10:00', 'proposed_duration_minutes' => 60]);

    $conflicts = $this->service->detectConflicts((string) $this->employer->_id, '2025-08-16', '10:00', 60);

    expect($conflicts)->toBeEmpty();
});

test('only accepted meetings are considered', function () {
    acceptedMeeting(['status' => 'pending', 'proposed_start_time' => '10:00', 'proposed_duration_minutes' => 60]);
    acceptedMeeting(['status' => 'cancelled', 'proposed_start_time' => '10:00', 'proposed_duration_minutes' => 60]);

    $conflicts = $this->service->detectConflicts((string) $this->employer->_id, '2025-08-15', '10:00', 60);

    expect($conflicts)->toBeEmpty();
});

test('conflicts are detected when the user is the invitee', function () {
    acceptedMeeting(['proposed_start_time' => '10:00', 'proposed_duration_minutes' => 60]);

    $conflicts = $this->service->detectConflicts((string) $this->seeker->_id, '2025-08-15', '10:30', 60);

    expect($conflicts)->toHaveCount(1);
});
