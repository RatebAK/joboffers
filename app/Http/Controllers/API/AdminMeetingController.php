<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminMeetingController extends Controller
{
    /**
     * List all meetings (admin)
     *
     * Returns a paginated list of all meetings on the platform with both participants' profile info.
     *
     * @queryParam status string Comma-separated statuses to filter by. Example: pending,accepted
     * @queryParam from_date string Filter meetings from this date (YYYY-MM-DD). Example: 2024-01-01
     * @queryParam to_date string Filter meetings up to this date (YYYY-MM-DD). Example: 2024-12-31
     * @queryParam sort_direction string Sort by proposed_date: asc or desc. Example: asc
     * @queryParam per_page integer Items per page (default 15, max 100). Example: 15
     *
     * @response 200 {
     *   "data": [],
     *   "current_page": 1,
     *   "per_page": 15,
     *   "total": 0,
     *   "total_pages": 1,
     *   "next_page": null,
     *   "prev_page": null
     * }
     */
    public function index(Request $request): JsonResponse
    {
        $query = Meeting::with(['organizer', 'invitee']);

        if ($status = $request->query('status')) {
            $statuses = explode(',', $status);
            $query->whereIn('status', $statuses);
        }

        if ($from = $request->query('from_date')) {
            $query->where('proposed_date', '>=', $from);
        }

        if ($to = $request->query('to_date')) {
            $query->where('proposed_date', '<=', $to);
        }

        $direction = $request->query('sort_direction', 'asc');
        $direction = in_array($direction, ['asc', 'desc']) ? $direction : 'asc';
        $query->orderBy('proposed_date', $direction);

        $perPage = min((int) $request->query('per_page', 15), 100) ?: 15;
        $paginator = $query->paginate($perPage);

        $items = collect($paginator->items())->map(fn ($m) => array_merge($m->toArray(), [
            'organizer' => $m->organizer ? ['name' => $m->organizer->name, 'email' => $m->organizer->email] : null,
            'invitee'   => $m->invitee ? ['name' => $m->invitee->name, 'email' => $m->invitee->email] : null,
        ]));

        return response()->json([
            'data'         => $items,
            'current_page' => $paginator->currentPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
            'total_pages'  => $paginator->lastPage(),
            'next_page'    => $paginator->currentPage() < $paginator->lastPage()
                                  ? $paginator->currentPage() + 1 : null,
            'prev_page'    => $paginator->currentPage() > 1
                                  ? $paginator->currentPage() - 1 : null,
        ]);
    }

    /**
     * Show a specific meeting (admin)
     *
     * Returns full meeting details with both participants' profile info. No ownership check.
     *
     * @urlParam id string required The meeting ID. Example: 664f1a2b3c4d5e6f7a8b9c0d
     *
     * @response 200 { "meeting": {} }
     * @response 404 { "message": "Meeting not found" }
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $meeting = Meeting::with(['organizer', 'invitee'])->find($id);

        if (! $meeting) {
            return response()->json(['message' => 'Meeting not found'], 404);
        }

        $data = array_merge($meeting->toArray(), [
            'organizer' => $meeting->organizer ? ['name' => $meeting->organizer->name, 'email' => $meeting->organizer->email] : null,
            'invitee'   => $meeting->invitee ? ['name' => $meeting->invitee->name, 'email' => $meeting->invitee->email] : null,
        ]);

        return response()->json(['meeting' => $data]);
    }
}
