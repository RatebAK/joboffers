<?php

namespace App\Services;

use App\Models\Meeting;
use App\Models\User;

class MeetingService
{
    public function __construct(
        private MeetingConflictService $conflictService,
        private MeetingNotificationService $notificationService,
        private GoogleMeetService $googleMeetService,
    ) {}

    /**
     * Create a new meeting invitation with conflict detection.
     */
    public function createMeeting(array $data, User $organizer): array
    {
        $meeting = Meeting::create([
            'organizer_id' => (string) $organizer->_id,
            'invitee_id' => $data['invitee_id'],
            'title' => $data['title'],
            'meeting_type' => $data['meeting_type'],
            'proposed_date' => $data['proposed_date'],
            'proposed_start_time' => $data['proposed_start_time'],
            'proposed_duration_minutes' => $data['proposed_duration_minutes'],
            'location_or_link' => $data['location_or_link'] ?? null,
            'status' => 'pending',
            'notes' => [],
            'previous_schedules' => [],
        ]);

        $organizerConflicts = $this->conflictService->detectConflicts(
            (string) $organizer->_id,
            $data['proposed_date'],
            $data['proposed_start_time'],
            $data['proposed_duration_minutes'],
        );

        $inviteeConflicts = $this->conflictService->detectConflicts(
            $data['invitee_id'],
            $data['proposed_date'],
            $data['proposed_start_time'],
            $data['proposed_duration_minutes'],
        );

        $this->notificationService->notifyInvitation($meeting);

        return [
            'meeting' => $meeting,
            'organizer_conflicts' => $organizerConflicts,
            'invitee_conflicts' => $inviteeConflicts,
        ];
    }

    /**
     * Accept a meeting invitation (invitee action).
     */
    public function acceptMeeting(Meeting $meeting, User $invitee, ?string $meetLink = null): array
    {
        $meeting->update(['status' => 'accepted']);

        $googleMeetWarning = null;

        if ($meeting->meeting_type === 'video_call') {
            $result = $this->googleMeetService->createCalendarEventWithMeet($meeting);

            if ($result === null && $meetLink) {
                $meeting->update(['meet_link' => $meetLink]);
            }

            if ($result === null && !$meetLink) {
                $googleMeetWarning = 'Google Meet link could not be generated. The organizer has not connected Google integration or an error occurred.';
            }
        }

        $this->notificationService->notifyAccepted($meeting);

        return [
            'meeting' => $meeting->fresh(),
            'google_meet_warning' => $googleMeetWarning,
        ];
    }

    /**
     * Decline a meeting invitation (invitee action).
     */
    public function declineMeeting(Meeting $meeting, User $invitee, ?string $reason): Meeting
    {
        $updateData = ['status' => 'declined'];

        if ($reason) {
            $updateData['decline_reason'] = $reason;
        }

        $meeting->update($updateData);

        $this->notificationService->notifyDeclined($meeting);

        return $meeting->fresh();
    }

    /**
     * Cancel a meeting (organizer or invitee action).
     */
    public function cancelMeeting(Meeting $meeting, User $user, ?string $reason): Meeting
    {
        $updateData = [
            'status' => 'cancelled',
            'cancelled_by' => (string) $user->_id,
        ];

        if ($reason) {
            $updateData['cancellation_reason'] = $reason;
        }

        $meeting->update($updateData);

        if ($meeting->google_calendar_event_id) {
            $this->googleMeetService->deleteCalendarEvent($meeting);
        }

        $this->notificationService->notifyCancelled($meeting, (string) $user->_id);

        return $meeting->fresh();
    }

    /**
     * Reschedule a meeting to a new time (organizer action).
     */
    public function rescheduleMeeting(Meeting $meeting, array $newSchedule): array
    {
        $previousSchedules = $meeting->previous_schedules ?? [];
        $previousSchedules[] = [
            'proposed_date' => $meeting->proposed_date,
            'proposed_start_time' => $meeting->proposed_start_time,
            'proposed_duration_minutes' => $meeting->proposed_duration_minutes,
        ];

        $meeting->update([
            'proposed_date' => $newSchedule['proposed_date'],
            'proposed_start_time' => $newSchedule['proposed_start_time'],
            'proposed_duration_minutes' => $newSchedule['proposed_duration_minutes'],
            'previous_schedules' => $previousSchedules,
            'status' => 'rescheduled',
        ]);

        if ($meeting->google_calendar_event_id) {
            $this->googleMeetService->updateCalendarEvent($meeting);
        }

        $organizerConflicts = $this->conflictService->detectConflicts(
            $meeting->organizer_id,
            $newSchedule['proposed_date'],
            $newSchedule['proposed_start_time'],
            $newSchedule['proposed_duration_minutes'],
            (string) $meeting->_id,
        );

        $inviteeConflicts = $this->conflictService->detectConflicts(
            $meeting->invitee_id,
            $newSchedule['proposed_date'],
            $newSchedule['proposed_start_time'],
            $newSchedule['proposed_duration_minutes'],
            (string) $meeting->_id,
        );

        $this->notificationService->notifyRescheduled($meeting);

        return [
            'meeting' => $meeting->fresh(),
            'organizer_conflicts' => $organizerConflicts,
            'invitee_conflicts' => $inviteeConflicts,
        ];
    }

    /**
     * Mark a meeting as completed (organizer action).
     */
    public function completeMeeting(Meeting $meeting): Meeting
    {
        $meeting->update(['status' => 'completed']);

        return $meeting->fresh();
    }
}
