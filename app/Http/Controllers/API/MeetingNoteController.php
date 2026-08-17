<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MeetingNoteController extends Controller
{
    /**
     * Add a note to a meeting
     *
     * Appends a note to the meeting's notes array. Only participants (organizer or invitee) can add notes.
     *
     * @urlParam id string required The meeting ID. Example: 664f1a2b3c4d5e6f7a8b9c0f
     * @bodyParam content string required Note content (1-2000 chars, cannot be whitespace only). Example: Please bring your portfolio.
     *
     * @response 201 { "meeting": { "notes": [{ "author_id": "...", "content": "...", "created_at": "..." }] } }
     * @response 403 { "message": "Forbidden" }
     * @response 404 { "message": "Meeting not found" }
     * @response 422 { "errors": { "content": ["The content field is required."] } }
     */
    public function store(Request $request, string $id)
    {
        $meeting = Meeting::find($id);

        if (!$meeting) {
            return response()->json(['message' => 'Meeting not found'], 404);
        }

        $user = $request->user();

        if ((string) $meeting->organizer_id !== (string) $user->_id &&
            (string) $meeting->invitee_id !== (string) $user->_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validator = Validator::make($request->all(), [
            'content' => 'required|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $content = trim($validator->validated()['content']);

        if ($content === '') {
            return response()->json(['errors' => ['content' => ['The content field cannot be empty or whitespace only.']]], 422);
        }

        $notes = $meeting->notes ?? [];
        $notes[] = [
            'author_id'  => (string) $user->_id,
            'content'    => $content,
            'created_at' => now()->toISOString(),
        ];
        $meeting->update(['notes' => $notes]);

        return response()->json(['meeting' => $meeting->fresh()], 201);
    }
}
