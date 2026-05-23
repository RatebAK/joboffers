<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Employer;

class EmployerController extends Controller
{
    // Any authenticated user can apply to become an employer
    public function apply(Request $request)
    {
        $request->validate([
            'document' => 'required|file|mimes:pdf,doc,docx,jpg,png|max:5120',
        ]);

        $user = $request->user();

        $file = $request->file('document');
        $path = $file->store('employer_docs', 'public');

        $employer = Employer::create([
            'user_id'       => $user->_id,
            'document_path' => $path,
            'document_name' => $file->getClientOriginalName(),
            'status'        => Employer::STATUS_PENDING,
        ]);

        return response()->json([
            'message'  => 'Employer application submitted.',
            'employer' => $employer,
        ], 201);
    }

    // User gets current status / latest
    public function status(Request $request)
    {
        $user = $request->user();
        
        // Find the latest employer application for this user
        $latest = Employer::where('user_id', $user->_id)
            ->latest()
            ->first();

        return response()->json([
            'is_employer' => (bool) $user->is_employer,
            'latest' => $latest,
        ]);
    }

    // Admin: list all pending
    public function index()
    {
        // $this->authorize('approve-employers');

        $pending = Employer::where('status', Employer::STATUS_PENDING)
            ->with('user')
            ->get();

        return response()->json($pending);
    }

    public function approve(Request $request, $id)
    {
        $employer = Employer::findOrFail($id);
        $user = $employer->user;

        $employer->update([
            'status'      => Employer::STATUS_APPROVED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        // Add employer role if not already present, and mark as approved
        $roles = $user->roles ?? [];
        if (!in_array('employer', $roles)) {
            $roles[] = 'employer';
        }
        $user->update(['roles' => $roles, 'is_employer' => true]);

        return response()->json([
            'message'  => 'Approved employer request.',
            'employer' => $employer,
        ]);
    }

    // Admin: reject a request
    public function reject(Request $request, $id)
    {
        $employer = Employer::findOrFail($id);

        $employer->update([
            'status' => Employer::STATUS_REJECTED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_notes' => $request->input('review_notes'),
        ]);

        return response()->json([
            'message' => 'Rejected employer request.',
            'employer' => $employer,
        ]);
    }
}
