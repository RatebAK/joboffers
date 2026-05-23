<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Employer;

class EmployerController extends Controller
{
    /**
     * Apply to become employer
     *
     * Any authenticated user can submit an employer application by uploading a supporting document. The application starts in `pending` status and must be approved by an admin.
     *
     * @bodyParam document file required PDF, DOC, DOCX, JPG, or PNG. Max 5 MB.
     *
     * @response 201 {
     *   "message": "Employer application submitted.",
     *   "employer": {
     *     "id": "664f1a2b3c4d5e6f7a8b9c0d",
     *     "user_id": "664f1a2b3c4d5e6f7a8b9c0e",
     *     "status": "pending",
     *     "document_name": "business_license.pdf"
     *   }
     * }
     * @response 422 { "errors": { "document": ["The document field is required."] } }
     */
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

    /**
     * Employer application status
     *
     * Returns the authenticated user's employer approval status and their latest application record.
     *
     * @response 200 {
     *   "is_employer": false,
     *   "latest": {
     *     "id": "664f1a2b3c4d5e6f7a8b9c0d",
     *     "status": "pending",
     *     "document_name": "business_license.pdf",
     *     "created_at": "2024-01-15T00:00:00Z"
     *   }
     * }
     */
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

    /**
     * List pending employer applications
     *
     * Returns all employer applications with `pending` status. Admin only.
     *
     * @response 200 [
     *   {
     *     "id": "664f1a2b3c4d5e6f7a8b9c0d",
     *     "user_id": "664f1a2b3c4d5e6f7a8b9c0e",
     *     "status": "pending",
     *     "document_name": "business_license.pdf",
     *     "user": { "id": "664f1a2b3c4d5e6f7a8b9c0e", "name": "John Employer", "email": "john@corp.com" }
     *   }
     * ]
     */
    public function index()
    {
        // $this->authorize('approve-employers');

        $pending = Employer::where('status', Employer::STATUS_PENDING)
            ->with('user')
            ->get();

        return response()->json($pending);
    }

    /**
     * Approve employer
     *
     * Approves an employer application. Grants the `employer` role to the user and sets `is_employer = true`. Admin only.
     *
     * @urlParam id string required The employer application ID. Example: 664f1a2b3c4d5e6f7a8b9c0d
     *
     * @response 200 {
     *   "message": "Approved employer request.",
     *   "employer": { "id": "664f1a2b3c4d5e6f7a8b9c0d", "status": "approved" }
     * }
     * @response 404 { "message": "No query results for model" }
     */
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

    /**
     * Reject employer
     *
     * Rejects an employer application. Admin only.
     *
     * @urlParam id string required The employer application ID. Example: 664f1a2b3c4d5e6f7a8b9c0d
     * @bodyParam review_notes string Optional rejection reason. Example: Insufficient documentation provided.
     *
     * @response 200 {
     *   "message": "Rejected employer request.",
     *   "employer": { "id": "664f1a2b3c4d5e6f7a8b9c0d", "status": "rejected", "review_notes": "Insufficient documentation." }
     * }
     * @response 404 { "message": "No query results for model" }
     */
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
