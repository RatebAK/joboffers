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
     * List all chat sessions for the authenticated job seeker.
     *
     * @response 200 {
     *   "data": [{ "id": "...", "title": "Resume tips", "created_at": "..." }]
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
     * Create a new coach chat session.
     *
     * @bodyParam title string optional Session title. Max 100 chars. Example: Resume review session
     *
     * @response 201 { "id": "...", "title": "Resume review session", "created_at": "..." }
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
     * Get a session with its full message history.
     *
     * @urlParam sessionId string required The session ID.
     *
     * @response 200 {
     *   "id": "...",
     *   "title": "Resume tips",
     *   "messages": [{ "role": "user", "content": "...", "created_at": "..." }]
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
     * Delete a coach chat session and all its messages.
     *
     * @urlParam sessionId string required The session ID.
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
     * Send a message in a coach chat session.
     *
     * @urlParam sessionId string required The session ID.
     * @bodyParam message string required Your message. Max 1000 chars. Example: How can I improve my resume?
     *
     * @response 200 { "response": "Here are some tips..." }
     * @response 404 { "message": "Session not found" }
     * @response 422 { "errors": { "message": ["The message field is required."] } }
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

        // Build history from previous messages
        $history = $session->messages()
            ->orderBy('created_at')
            ->get(['role', 'content'])
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->toArray();

        try {
            $aiResponse = $coachService->chat($userMessage, $history);
        } catch (CvAnalysisException $e) {
            if ($e->getHttpStatusCode() === 422) {
                return response()->json([
                    'message' => 'Resume coach request failed',
                    'reason'  => $e->getMessage(),
                ], 422);
            }

            return response()->json(['message' => 'Resume coach service unavailable'], 502);
        }

        // Persist both turns
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
