<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MeetingNoteController extends Controller
{
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
