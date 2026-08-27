<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MongoDB\BSON\ObjectId;

class NotificationController extends Controller
{
    /**
     * List notifications for the authenticated user
     *
     * [NEW API] Returns a paginated list of in-app notifications for the currently authenticated user,
     * ordered newest first. Available to all authenticated users (employee, employer, admin).
     *
     * @queryParam per_page integer Number of results per page (default: 15). Example: 15
     * @queryParam page integer Page number. Example: 1
     *
     * @response 200 {
     *   "data": [
     *     {
     *       "id": "664f1a2b3c4d5e6f7a8b9c0d",
     *       "type": "application_status_changed",
     *       "message": "Your application status has been updated to: reviewed.",
     *       "read_at": null,
     *       "related_entity_id": "664f1a2b3c4d5e6f7a8b9c0e",
     *       "related_entity_type": "Application",
     *       "created_at": "2024-01-15T10:00:00Z"
     *     }
     *   ],
     *   "current_page": 1,
     *   "per_page": 15,
     *   "total": 42,
     *   "total_pages": 3,
     *   "next_page": 2,
     *   "prev_page": null
     * }
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 15);

        $paginator = $this->forAuthUser()
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        $items = collect($paginator->items())->map(fn ($n) => $this->formatNotification($n));

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
     * Mark a notification as read
     *
     * [NEW API] Sets the `read_at` timestamp on a specific notification owned by the authenticated user.
     *
     * @urlParam id string required The notification ID. Example: 664f1a2b3c4d5e6f7a8b9c0d
     *
     * @response 200 {
     *   "id": "664f1a2b3c4d5e6f7a8b9c0d",
     *   "type": "application_status_changed",
     *   "message": "Your application status has been updated to: reviewed.",
     *   "read_at": "2024-01-15T11:00:00Z",
     *   "related_entity_id": "664f1a2b3c4d5e6f7a8b9c0e",
     *   "related_entity_type": "Application",
     *   "created_at": "2024-01-15T10:00:00Z"
     * }
     * @response 404 { "message": "Notification not found." }
     */
    public function markRead(string $id): JsonResponse
    {
        $notification = $this->forAuthUser()->find($id);

        if (! $notification) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        if (! $notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json($this->formatNotification($notification));
    }

    /**
     * Mark all notifications as read
     *
     * [NEW API] Bulk-updates all unread notifications for the authenticated user, setting `read_at` to now.
     *
     * @response 200 { "message": "All notifications marked as read.", "updated": 5 }
     */
    public function markAllRead(): JsonResponse
    {
        $updated = $this->forAuthUser()
            ->whereNull('read_at')
            ->get();

        $count = $updated->count();
        foreach ($updated as $notification) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json([
            'message' => 'All notifications marked as read.',
            'updated' => $count,
        ]);
    }

    /**
     * Get unread notification count
     *
     * [NEW API] Returns the count of unread notifications for the authenticated user.
     *
     * @response 200 { "unread_count": 3 }
     */
    public function unreadCount(): JsonResponse
    {
        $count  = $this->forAuthUser()
            ->whereNull('read_at')
            ->count();

        return response()->json(['unread_count' => $count]);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Build a query scoped to the authenticated user's notifications,
     * matching against both string and ObjectId representations of user_id
     * to be resilient against how data was originally persisted.
     */
    private function forAuthUser(): \MongoDB\Laravel\Eloquent\Builder
    {
        $strId = (string) auth()->id();

        try {
            $oid = new ObjectId($strId);
            return Notification::where(function ($q) use ($strId, $oid) {
                $q->where('user_id', $strId)->orWhere('user_id', $oid);
            });
        } catch (\Throwable) {
            return Notification::where('user_id', $strId);
        }
    }

    private function formatNotification(Notification $n): array
    {
        return [
            'id'                  => (string) $n->_id,
            'type'                => $n->type,
            'message'             => $n->message,
            'read_at'             => $n->read_at?->toISOString(),
            'related_entity_id'   => $n->related_entity_id,
            'related_entity_type' => $n->related_entity_type,
            'created_at'          => $n->created_at?->toISOString(),
        ];
    }
}
