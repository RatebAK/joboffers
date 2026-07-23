<?php

namespace App\Http\Controllers\API;

use App\Exceptions\CvAnalysisException;
use App\Http\Controllers\Controller;
use App\Models\CoachMessage;
use App\Models\CoachSession;
use App\Services\ResumeCoachService;
use Illuminate\Http\Request;

class ResumeCoachController extends Controller
{
    /**
     * List coach sessions
     *
     * Returns all chat sessions for the authenticated job seeker, newest first.
     *
     * @authenticated
     *
     * @group Resume Coach
     *
     * @response 200 {
     *   "data": [{ "id": "...", "title": "Resume tips", "created_at": "...", "updated_at": "..." }]
     * }
     */
    public function listSessions(Request $request)
    {
        $sessions = CoachSession::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->get(['_id', 'title', 'created_at', 'updated_at']);

        return response()->json(['data' => $sessions]);
    }

    /**
     * Chat with resume coach
     *
     * Sends a message to the AI resume coach. The AI automatically creates or continues
     * a session for the user. Both the user message and the AI response are persisted
     * in the shared database and will appear in subsequent session/message list calls.
     *
     * @authenticated
     * @group Resume Coach
     *
     * @bodyParam message string required Your message to the coach. Max 1000 chars. Example: How can I improve my resume?
     *
     * @response 200 { "response": "Here are some tips to improve your resume...", "session_id": "64a1b2c3d4e5f6a7b8c9d0e1" }
     * @response 422 scenario="Validation error" { "errors": { "message": ["The message field is required."] } }
     * @response 422 scenario="Service rejected request" { "message": "Resume coach request failed", "reason": "..." }
     * @response 502 { "message": "Resume coach service unavailable" }
     */
    public function chat(Request $request, ResumeCoachService $coachService)
    {
        $request->validate(['message' => 'required|string|max:1000']);

        $userId = (string) $request->user()->_id;

        try {
            $result = $coachService->chat($userId, $request->input('message'));
        } catch (CvAnalysisException $e) {
            if ($e->getHttpStatusCode() === 422) {
                return response()->json([
                    'message' => 'Resume coach request failed',
                    'reason'  => $e->getMessage(),
                ], 422);
            }

            return response()->json(['message' => 'Resume coach service unavailable'], 502);
        }

        return response()->json([
            'response'   => $result['response'],
            'session_id' => $result['session_id'],
        ]);
    }
}
