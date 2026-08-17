<?php

namespace App\Services;

use App\Models\Meeting;

class MeetingConflictService
{
    /**
     * Detect scheduling conflicts for a user on a given date.
     *
     * Returns an array of conflicting meetings (each with id, proposed_date,
     * proposed_start_time, proposed_duration_minutes).
     *
     * Overlap rule: two meetings overlap when startA < endB AND startB < endA
     * (adjacent meetings sharing only an endpoint do NOT conflict).
     */
    public function detectConflicts(
        string $userId,
        string $date,
        string $startTime,
        int $durationMinutes,
        ?string $excludeMeetingId = null
    ): array {
        $query = Meeting::where('proposed_date', $date)
            ->where('status', 'accepted')
            ->where(function ($q) use ($userId) {
                $q->where('organizer_id', $userId)
                  ->orWhere('invitee_id', $userId);
            });

        if ($excludeMeetingId) {
            $query->where('_id', '!=', $excludeMeetingId);
        }

        $existingMeetings = $query->get();

        $newStartMinutes = $this->timeToMinutes($startTime);
        $newEndMinutes = $newStartMinutes + $durationMinutes;

        $conflicts = [];

        foreach ($existingMeetings as $meeting) {
            $meetingStartMinutes = $this->timeToMinutes($meeting->proposed_start_time);
            $meetingEndMinutes = $meetingStartMinutes + $meeting->proposed_duration_minutes;

            // Overlap exists when: startA < endB AND startB < endA
            if ($newStartMinutes < $meetingEndMinutes && $meetingStartMinutes < $newEndMinutes) {
                $conflicts[] = [
                    'id' => (string) $meeting->_id,
                    'proposed_date' => $meeting->proposed_date,
                    'proposed_start_time' => $meeting->proposed_start_time,
                    'proposed_duration_minutes' => $meeting->proposed_duration_minutes,
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Convert a HH:MM time string to minutes since midnight.
     */
    private function timeToMinutes(string $time): int
    {
        $parts = explode(':', $time);

        return (int) $parts[0] * 60 + (int) $parts[1];
    }
}
