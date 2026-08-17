<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateMeetingRequest;
use App\Models\Meeting;
use App\Models\User;
use App\Services\MeetingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MeetingController extends Controller
{
    public function __construct(private MeetingService $meetingService) {}

    /**
     * Create a meeting invitation
     *
     * Creates a new meeting between the authenticated user and an invitee with the opposite role.
     * Returns the created meeting along with any scheduling conflicts detected for both participants.
     *
     * @bodyParam invitee_id string required The target user's ID. Example: 664f1a2b3c4d5e6f7a8b9c0e
     * @bodyParam title string required Meeting title (1-255 chars). Example: Initial Interview
     * @bodyParam meeting_type string required One of: in_person, phone_call, video_call. Example: video_call
     * @bodyParam proposed_date string required Future date (YYYY-MM-DD). Example: 2025-02-15
     * @bodyParam proposed_start_time string required Start time (HH:MM). Example: 14:00
     * @bodyParam proposed_duration_minutes integer required Duration in minutes (15-480). Example: 60
     * @bodyParam location_or_link string optional Location or link (max 500 chars, ignored for video_call). Example: 123 Main St
     *
     * @response 201 {
     *   "message": "Meeting created successfully",
     *   "meeting": {},
     *   "organizer_conflicts": [],
     *   "invitee_conflicts": []
     * }
     * @response 422 { "message": "Invitee not found" }
     */
    public function store(CreateMeetingRequest $request)
    {
        $user = $request->user();
        $validated = $request->validated();

        // Validate invitee exists
        $invitee = User::find($validated['invitee_id']);
        if (!$invitee) {
            return response()->json(['message' => 'Invitee not found'], 422);
        }

        // Cannot invite yourself
        if ((string) $invitee->_id === (string) $user->_id) {
            return response()->json(['message' => 'You cannot create a meeting with yourself'], 422);
        }

        // Validate opposite role
        if ($user->hasRole('employer') && !$invitee->hasRole('employee')) {
            return response()->json(['message' => 'Invitee must be a job seeker'], 422);
        }
        if ($user->hasRole('employee') && !$invitee->hasRole('employer')) {
            return response()->json(['message' => 'Invitee must be an employer'], 422);
        }

        // For video_call meetings, ignore location_or_link
        if ($validated['meeting_type'] === 'video_call') {
            $validated['location_or_link'] = null;
        }

        $result = $this->meetingService->createMeeting($validated, $user);

        return response()->json([
            'message' => 'Meeting created successfully',
            'meeting' => $result['meeting'],
            'organizer_conflicts' => $result['organizer_conflicts'],
            'invitee_conflicts' => $result['invitee_conflicts'],
        ], 201);
    }

    /**
     * List meetings
     *
     * Returns a paginated list of meetings where the authenticated user is a participant.
     * Supports filtering by status, date range, and sort direction.
     *
     * @queryParam status string Comma-separated Meeting_Status values. Example: pending,accepted
     * @queryParam from_date string ISO date (YYYY-MM-DD). Example: 2025-01-01
     * @queryParam to_date string ISO date (YYYY-MM-DD). Example: 2025-12-31
     * @queryParam sort_direction string asc or desc. Default: asc. Example: desc
     * @queryParam per_page integer Items per page (1-100, default 15). Example: 20
     *
     * @response 200 {
     *   "data": [],
     *   "current_page": 1,
     *   "per_page": 15,
     *   "total": 0,
     *   "total_pages": 0,
     *   "next_page": null,
     *   "prev_page": null
     * }
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $validStatuses = ['pending', 'accepted', 'declined', 'cancelled', 'rescheduled', 'completed'];

        // Validate filters
        $filterValidator = Validator::make($request->all(), [
            'status' => ['nullable', 'string'],
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d'],
            'sort_direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($filterValidator->fails()) {
            return response()->json(['errors' => $filterValidator->errors()], 422);
        }

        // Validate status values if provided
        if ($request->has('status') && $request->status !== null) {
            $statuses = array_map('trim', explode(',', $request->status));
            $invalidStatuses = array_diff($statuses, $validStatuses);
            if (!empty($invalidStatuses)) {
                return response()->json([
                    'message' => 'Invalid status value(s): ' . implode(', ', $invalidStatuses),
                ], 422);
            }
        }

        $query = Meeting::where(function ($q) use ($user) {
            $q->where('organizer_id', (string) $user->_id)
              ->orWhere('invitee_id', (string) $user->_id);
        });

        // Apply status filter
        if ($request->filled('status')) {
            $statuses = array_map('trim', explode(',', $request->status));
            $query->whereIn('status', $statuses);
        }

        // Apply date range filters
        if ($request->filled('from_date')) {
            $query->where('proposed_date', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $query->where('proposed_date', '<=', $request->to_date);
        }

        // Sort
        $sortDirection = $request->input('sort_direction', 'asc');
        $query->orderBy('proposed_date', $sortDirection);

        // Paginate
        $perPage = min((int) $request->input('per_page', 15), 100);
        $paginated = $query->paginate($perPage);

        // Transform data to include participant info
        $data = $paginated->getCollection()->map(function ($meeting) use ($user) {
            $otherParticipant = (string) $meeting->organizer_id === (string) $user->_id
                ? User::find($meeting->invitee_id)
                : User::find($meeting->organizer_id);

            $meetingData = $meeting->toArray();
            $meetingData['participant'] = $otherParticipant ? [
                'name' => $otherParticipant->name,
                'email' => $otherParticipant->email,
                'company_name' => $otherParticipant->company_name ?? null,
            ] : null;

            return $meetingData;
        });

        return response()->json([
            'data' => $data,
            'current_page' => $paginated->currentPage(),
            'per_page' => $paginated->perPage(),
            'total' => $paginated->total(),
            'total_pages' => $paginated->lastPage(),
            'next_page' => $paginated->currentPage() < $paginated->lastPage() ? $paginated->currentPage() + 1 : null,
            'prev_page' => $paginated->currentPage() > 1 ? $paginated->currentPage() - 1 : null,
        ]);
    }

    /**
     * Show meeting details
     *
     * Returns full details of a specific meeting. Only accessible by participants.
     *
     * @urlParam id string required The meeting ID. Example: 664f1a2b3c4d5e6f7a8b9c0f
     *
     * @response 200 { "meeting": {} }
     * @response 403 { "message": "Forbidden" }
     * @response 404 { "message": "Meeting not found" }
     */
    public function show(Request $request, string $id)
    {
        $user = $request->user();

        $meeting = Meeting::find($id);
        if (!$meeting) {
            return response()->json(['message' => 'Meeting not found'], 404);
        }

        // Check participant access
        if ((string) $meeting->organizer_id !== (string) $user->_id &&
            (string) $meeting->invitee_id !== (string) $user->_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Include other participant's profile info
        $otherParticipant = (string) $meeting->organizer_id === (string) $user->_id
            ? User::find($meeting->invitee_id)
            : User::find($meeting->organizer_id);

        $meetingData = $meeting->toArray();
        $meetingData['participant'] = $otherParticipant ? [
            'name' => $otherParticipant->name,
            'email' => $otherParticipant->email,
            'company_name' => $otherParticipant->company_name ?? null,
        ] : null;

        return response()->json(['meeting' => $meetingData]);
    }

    /**
     * Upcoming meetings
     *
     * Returns the next 5 accepted meetings where the user is a participant,
     * sorted by proposed_date ascending.
     *
     * @response 200 {
     *   "meetings": [
     *     {
     *       "id": "664f1a2b3c4d5e6f7a8b9c0f",
     *       "title": "Interview",
     *       "proposed_date": "2025-02-15",
     *       "proposed_start_time": "14:00",
     *       "proposed_duration_minutes": 60,
     *       "meeting_type": "video_call",
     *       "participant_name": "Jane Smith"
     *     }
     *   ]
     * }
     */
    public function upcoming(Request $request)
    {
        $user = $request->user();
        $today = now()->toDateString();

        $meetings = Meeting::where(function ($q) use ($user) {
            $q->where('organizer_id', (string) $user->_id)
              ->orWhere('invitee_id', (string) $user->_id);
        })
            ->where('status', 'accepted')
            ->where('proposed_date', '>', $today)
            ->orderBy('proposed_date', 'asc')
            ->limit(5)
            ->get();

        $result = $meetings->map(function ($meeting) use ($user) {
            $otherParticipant = (string) $meeting->organizer_id === (string) $user->_id
                ? User::find($meeting->invitee_id)
                : User::find($meeting->organizer_id);

            return [
                'id' => (string) $meeting->_id,
                'title' => $meeting->title,
                'proposed_date' => $meeting->proposed_date,
                'proposed_start_time' => $meeting->proposed_start_time,
                'proposed_duration_minutes' => $meeting->proposed_duration_minutes,
                'meeting_type' => $meeting->meeting_type,
                'participant_name' => $otherParticipant->name ?? null,
            ];
        });

        return response()->json(['meetings' => $result]);
    }
}
