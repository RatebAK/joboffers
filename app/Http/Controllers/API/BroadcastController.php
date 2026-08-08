<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\BroadcastRequest;
use App\Services\AuditLogService;
use App\Services\BroadcastService;
use Illuminate\Http\JsonResponse;

class BroadcastController extends Controller
{
    /**
     * Send a platform-wide broadcast
     *
     * [NEW API] Dispatches an email and in-app notification to a defined audience. Email delivery and
     * in-app notification creation are processed asynchronously via Laravel queues.
     *
     * Audience values: `employees` (all job seekers), `employers` (all approved employers),
     * `all` (everyone except admins). Alternatively, provide `user_ids` to target specific users.
     *
     * @bodyParam subject string required Notification subject line. Example: New feature launch
     * @bodyParam body string required Notification body text. Example: We just launched job matching AI!
     * @bodyParam audience string required when user_ids not provided. One of: employees, employers, all. Example: employees
     * @bodyParam user_ids string[] Optional array of specific user IDs to target (overrides audience).
     *
     * @response 200 {
     *   "status": "queued",
     *   "recipient_count": 142
     * }
     * @response 422 { "errors": { "subject": ["The subject field is required."] } }
     */
    public function send(BroadcastRequest $request, BroadcastService $service): JsonResponse
    {
        $actor     = $request->user();
        $subject   = $request->input('subject');
        $body      = $request->input('body');
        $audience  = $request->input('audience', 'all');
        $userIds   = $request->input('user_ids');

        $recipients     = $service->resolveRecipients($audience, $userIds);
        $recipientCount = $service->dispatch($recipients, $subject, $body);

        AuditLogService::log(
            action:     'broadcast_sent',
            actorId:    (string) $actor->_id,
            actorName:  $actor->name,
            targetId:   null,
            targetType: null,
            metadata:   [
                'audience'        => $userIds ? 'user_ids' : $audience,
                'subject'         => $subject,
                'recipient_count' => $recipientCount,
            ]
        );

        return response()->json([
            'status'          => 'queued',
            'recipient_count' => $recipientCount,
        ]);
    }
}
