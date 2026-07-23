<?php

namespace App\Http\Controllers\API;

use App\Exceptions\CvAnalysisException;
use App\Http\Controllers\Controller;
use App\Models\CoachMessage;
use App\Models\CoachSession;
use App\Services\ResumeCoachService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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
     * Create a coach session
     *
     * Creates a new resume coach chat session. The title defaults to "New Conversation"
     * and will be automatically updated to the first 60 characters of the first message sent.
     *
     * @authenticated
     *
     * @group Resume Coach
     *
     * @bodyParam title string optional Session title. Max 100 chars. Example: Resume review session
     *
     * @response 201 { "id": "...", "title": "Resume review session", "created_at": "...", "updated_at": "..." }
     * @response 422 { "errors": { "title": ["The title may not be greater than 100 characters."] } }
     */
    public function createSession(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $session = CoachSession::create([
            'user_id' => $request->user()->id,
            'title'   => $request->input('title', 'New Conversation'),
        ]);

        return response()->json($session, 201);
    }

    /**
     * Get a coach session
     *
     * Returns the session details along with its full message history in chronological order.
     *
     * @authenticated
     *
     * @group Resume Coach
     *
     * @urlParam sessionId string required The session ID. Example: 64a1b2c3d4e5f6a7b8c9d0e1
     *
     * @response 200 {
     *   "id": "...",
     *   "title": "Resume tips",
     *   "messages": [
     *     { "role": "user", "content": "How do I improve my CV?", "created_at": "..." },
     *     { "role": "assistant", "content": "Focus on quantifying achievements...", "created_at": "..." }
     *   ],
     *   "created_at": "...",
     *   "updated_at": "..."
     * }
     * @response 404 { "message": "Session not found" }
     */
    public function getSession(Request $request, string $sessionId)
    {
        $session = CoachSession::where('_id', $sessionId)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $session) {
            return response()->json(['message' => 'Session not found'], 404);
        }

        $session->messages = $session->messages()->orderBy('created_at')->get(['role', 'content', 'created_at']);

        return response()->json($session);
    }

    /**
     * Delete a coach session
     *
     * Permanently deletes the session and all of its messages.
     *
     * @authenticated
     *
     * @group Resume Coach
     *
     * @urlParam sessionId string required The session ID. Example: 64a1b2c3d4e5f6a7b8c9d0e1
     *
     * @response 200 { "message": "Session deleted" }
     * @response 404 { "message": "Session not found" }
     */
    public function deleteSession(Request $request, string $sessionId)
    {
        $session = CoachSession::where('_id', $sessionId)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $session) {
            return response()->json(['message' => 'Session not found'], 404);
        }

        CoachMessage::where('session_id', $session->id)->delete();
        $session->delete();

        return response()->json(['message' => 'Session deleted']);
    }

    /**
     * Chat with resume coach
     *
     * Sends a message to the AI resume coach within a session. The full conversation
     * history is automatically passed to the AI for context. Both the user message and
     * the AI response are persisted and will appear in subsequent `GET /sessions/{id}` calls.
     *
     * @authenticated
     *
     * @group Resume Coach
     *
     * @urlParam sessionId string required The session ID. Example: 64a1b2c3d4e5f6a7b8c9d0e1
     *
     * @bodyParam message string required Your message to the coach. Max 1000 chars. Example: How can I improve my resume?
     *
     * @response 200 { "response": "Here are some tips to improve your resume..." }
     * @response 404 { "message": "Session not found" }
     * @response 422 scenario="Validation error" { "errors": { "message": ["The message field is required."] } }
     * @response 422 scenario="Service rejected request" { "message": "Resume coach request failed", "reason": "Message contains unsupported content" }
     * @response 502 { "message": "Resume coach service unavailable" }
     */
    public function chat(Request $request, ResumeCoachService $coachService, string $sessionId)
    {
        $session = CoachSession::where('_id', $sessionId)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $session) {
            return response()->json(['message' => 'Session not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $userMessage = $request->input('message');
        $userId      = (string) $request->user()->_id;

        try {
            $result = $coachService->chat($userId, $userMessage);
        } catch (CvAnalysisException $e) {
            if ($e->getHttpStatusCode() === 422) {
                return response()->json([
                    'message' => 'Resume coach request failed',
                    'reason'  => $e->getMessage(),
                ], 422);
            }

            return response()->json(['message' => 'Resume coach service unavailable'], 502);
        }

        $aiResponse = $result['response'];

        // Persist both turns locally
        CoachMessage::create(['session_id' => $session->id, 'role' => 'user',      'content' => $userMessage]);
        CoachMessage::create(['session_id' => $session->id, 'role' => 'assistant', 'content' => $aiResponse]);

        // Update session title from the first user message if it's still the default
        if ($session->title === 'New Conversation') {
            $session->title = mb_substr($userMessage, 0, 60);
            $session->save();
        }

        return response()->json(['response' => $aiResponse]);
    }
}
