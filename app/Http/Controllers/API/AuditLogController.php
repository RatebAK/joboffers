<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /**
     * View audit log
     *
     * [NEW API] Returns a paginated, read-only list of sensitive admin actions. Supports filtering by
     * action type and date range.
     *
     * Valid `action_type` values:
     * - `employer_approved`
     * - `employer_rejected`
     * - `broadcast_sent`
     * - `cv_reanalysis_triggered`
     * - `bulk_employer_onboarded`
     *
     * @queryParam action_type string Filter by action type. Example: broadcast_sent
     * @queryParam date_from string ISO date — return entries on or after this date. Example: 2024-01-01
     * @queryParam date_to string ISO date — return entries on or before this date. Example: 2024-12-31
     * @queryParam per_page integer Results per page (default: 20). Example: 20
     * @queryParam page integer Page number. Example: 1
     *
     * @response 200 {
     *   "data": [
     *     {
     *       "id": "664f1a2b3c4d5e6f7a8b9c0d",
     *       "action": "employer_approved",
     *       "actor_id": "664f1a2b3c4d5e6f7a8b9c0e",
     *       "actor_name": "Admin User",
     *       "target_id": "664f1a2b3c4d5e6f7a8b9c0f",
     *       "target_type": "Employer",
     *       "metadata": {},
     *       "created_at": "2024-01-15T10:00:00Z"
     *     }
     *   ],
     *   "current_page": 1, "per_page": 20, "total": 42, "total_pages": 3, "next_page": 2, "prev_page": null
     * }
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));
        $query   = AuditLog::query()->orderBy('created_at', 'desc');

        if ($actionType = $request->query('action_type')) {
            $query->where('action', $actionType);
        }

        if ($dateFrom = $request->query('date_from')) {
            $query->where('created_at', '>=', $dateFrom . ' 00:00:00');
        }

        if ($dateTo = $request->query('date_to')) {
            $query->where('created_at', '<=', $dateTo . ' 23:59:59');
        }

        $paginator = $query->paginate($perPage);
        $items     = collect($paginator->items())->map(fn ($log) => [
            'id'          => (string) $log->_id,
            'action'      => $log->action,
            'actor_id'    => $log->actor_id,
            'actor_name'  => $log->actor_name,
            'target_id'   => $log->target_id,
            'target_type' => $log->target_type,
            'metadata'    => $log->metadata ?? [],
            'created_at'  => $log->created_at?->toISOString(),
        ]);

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
}
