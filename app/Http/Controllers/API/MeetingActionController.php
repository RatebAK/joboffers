<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\RescheduleMeetingRequest;
use App\Models\Meeting;
use App\Services\MeetingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MeetingActionController extends Controller
{
    public function __construct(
        private MeetingService $meetingService,
    ) {}

    /**
     * Accept a meeting invitation
     *
     * Allows the invitee to accept a pending or rescheduled meeting. For video_call meetings,
     * optionally provide a meet_link as a fallback if Google integration is not configured.
     *
     * @urlParam id string required The meeting ID. Example: 664f1a2b3c4d5e6f7a8b9c0f
     * @bodyParam meet_link string Optional fallback video conference URL (max 500 chars). Example: https://zoom.us/j/123456
     *
     * @response 200 {
     *   "meeting": {
     *     "_id": "664f1a2b3c4d5e6f7a8b9c0f",
     *     "title": "Initial Interview",
     *     "status": "accepted",
     *     "meeting_type": "video_call",
     *     "meet_link": "https://meet.google.com/abc-defg-hij"
     *   },
     *   "google_meet_warning": null
     * }
     * @response 403 { "message": "Forbidden" }
     * @response 404 { "message": "Meeting not found" }
     * @response 422 { "message": "This meeting cannot be accepted in its current state" }
     */
    public function accept(Request $request, string $id)
    {
        $user = $request->user();

        try {
            $meeting = Meeting::findOrFail($id);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Meeting not found'], 404);
        }

        if ((string) $meeting->invitee_id !== (string) $user->_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!in_array($meeting->status, ['pending', 'rescheduled'])) {
            return response()->json(['message' => 'This meeting cannot be accepted in its current state'], 422);
        }

        $validator = Validator::make($request->all(), [
            'meet_link' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $meetLink = $request->input('meet_link');

        $result = $this->meetingService->acceptMeeting($meeting, $user, $meetLink);

        return response()->json([
            'meeting' => $result['meeting'],
            'google_meet_warning' => $result['google_meet_warning'] ?? null,
        ]);
    }

    /**
     * Decline a meeting invitation
     *
     * Allows the invitee to decline a pending or rescheduled meeting with an optional reason.
     *
     * @urlParam id string required The meeting ID. Example: 664f1a2b3c4d5e6f7a8b9c0f
     * @bodyParam decline_reason string Optional reason for declining (max 500 chars). Example: Schedule conflict
     *
     * @response 200 { "meeting": { "_id": "664f...", "status": "declined", "decline_reason": "Schedule conflict" } }
     * @response 403 { "message": "Forbidden" }
     * @response 404 { "message": "Meeting not found" }
     * @response 422 { "message": "This meeting cannot be declined in its current state" }
     */
    public function decline(Request $request, string $id)
    {
        $user = $request->user();

        try {
            $meeting = Meeting::findOrFail($id);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Meeting not found'], 404);
        }

        if ((string) $meeting->invitee_id !== (string) $user->_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!in_array($meeting->status, ['pending', 'rescheduled'])) {
            return response()->json(['message' => 'This meeting cannot be declined in its current state'], 422);
        }

        $validator = Validator::make($request->all(), [
            'decline_reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $reason = $request->input('decline_reason');

        $meeting = $this->meetingService->declineMeeting($meeting, $user, $reason);

        return response()->json(['meeting' => $meeting]);
    }

    /**
     * Cancel a meeting
     *
     * Allows the organizer or invitee to cancel a meeting. Organizer can cancel from
     * pending/accepted/rescheduled. Invitee can cancel from accepted/rescheduled only.
     *
     * @urlParam id string required The meeting ID. Example: 664f1a2b3c4d5e6f7a8b9c0f
     * @bodyParam cancellation_reason string Optional reason for cancelling (max 500 chars). Example: No longer needed
     *
     * @response 200 { "meeting": { "_id": "664f...", "status": "cancelled", "cancellation_reason": "No longer needed", "cancelled_by": "664f..." } }
     * @response 403 { "message": "Forbidden" }
     * @response 404 { "message": "Meeting not found" }
     * @response 422 { "message": "This meeting cannot be cancelled in its current state" }
     */
    public function cancel(Request $request, string $id)
    {
        $user = $request->user();

        try {
            $meeting = Meeting::findOrFail($id);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Meeting not found'], 404);
        }

        $isOrganizer = (string) $meeting->organizer_id === (string) $user->_id;
        $isInvitee = (string) $meeting->invitee_id === (string) $user->_id;

        if (!$isOrganizer && !$isInvitee) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $terminalStatuses = ['cancelled', 'declined', 'completed'];
        if (in_array($meeting->status, $terminalStatuses)) {
            return response()->json(['message' => 'This meeting cannot be cancelled in its current state'], 422);
        }

        if ($isInvitee && $meeting->status === 'pending') {
            return response()->json(['message' => 'A pending meeting must be declined rather than cancelled'], 422);
        }

        if ($isOrganizer && !in_array($meeting->status, ['pending', 'accepted', 'rescheduled'])) {
            return response()->json(['message' => 'This meeting cannot be cancelled in its current state'], 422);
        }

        if ($isInvitee && !in_array($meeting->status, ['accepted', 'rescheduled'])) {
            return response()->json(['message' => 'This meeting cannot be cancelled in its current state'], 422);
        }

        $validator = Validator::make($request->all(), [
            'cancellation_reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $reason = $request->input('cancellation_reason');

        $meeting = $this->meetingService->cancelMeeting($meeting, $user, $reason);

        return response()->json(['meeting' => $meeting]);
    }

    /**
     * Reschedule a meeting
     *
     * Allows the organizer to reschedule a meeting to a new time. The previous schedule
     * is preserved in the meeting history.
     *
     * @urlParam id string required The meeting ID. Example: 664f1a2b3c4d5e6f7a8b9c0f
     * @bodyParam proposed_date string required New proposed date (must be after today). Example: 2025-02-15
     * @bodyParam proposed_start_time string required New proposed start time. Example: 14:00
     * @bodyParam proposed_duration_minutes integer required New duration in minutes (15-480). Example: 60
     *
     * @response 200 { "meeting": { "_id": "664f...", "status": "rescheduled", "proposed_date": "2025-02-20", "previous_schedules": [{"proposed_date": "2025-02-15", "proposed_start_time": "14:00", "proposed_duration_minutes": 60}] }, "organizer_conflicts": [], "invitee_conflicts": [] }
     * @response 403 { "message": "Forbidden" }
     * @response 404 { "message": "Meeting not found" }
     * @response 422 { "message": "This meeting cannot be rescheduled in its current state" }
     */
    public function reschedule(RescheduleMeetingRequest $request, string $id)
    {
        $user = $request->user();

        try {
            $meeting = Meeting::findOrFail($id);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Meeting not found'], 404);
        }

        if ((string) $meeting->organizer_id !== (string) $user->_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $terminalStatuses = ['cancelled', 'declined', 'completed'];
        if (in_array($meeting->status, $terminalStatuses)) {
            return response()->json(['message' => 'This meeting cannot be rescheduled in its current state'], 422);
        }

        $validated = $request->validated();

        $result = $this->meetingService->rescheduleMeeting($meeting, $validated);

        return response()->json([
            'meeting' => $result['meeting'],
            'organizer_conflicts' => $result['organizer_conflicts'],
            'invitee_conflicts' => $result['invitee_conflicts'],
        ]);
    }

    /**
     * Mark a meeting as completed
     *
     * Allows the organizer to mark an accepted meeting as completed after it has occurred.
     *
     * @urlParam id string required The meeting ID. Example: 664f1a2b3c4d5e6f7a8b9c0f
     *
     * @response 200 { "meeting": { "_id": "664f...", "status": "completed" } }
     * @response 403 { "message": "Forbidden" }
     * @response 404 { "message": "Meeting not found" }
     * @response 422 { "message": "Only accepted meetings can be marked as completed" }
     */
    public function complete(Request $request, string $id)
    {
        $user = $request->user();

        try {
            $meeting = Meeting::findOrFail($id);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Meeting not found'], 404);
        }

        if ((string) $meeting->organizer_id !== (string) $user->_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($meeting->status !== 'accepted') {
            return response()->json(['message' => 'Only accepted meetings can be marked as completed'], 422);
        }

        if (Carbon::parse($meeting->proposed_date)->gte(Carbon::today())) {
            return response()->json(['message' => 'This meeting has not yet occurred'], 422);
        }

        $meeting = $this->meetingService->completeMeeting($meeting);

        return response()->json(['meeting' => $meeting]);
    }
}
