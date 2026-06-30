<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\DirectOffer;
use App\Models\JobPost;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DirectOfferController extends Controller
{
    /**
     * Send direct offer
     *
     * Sends a direct job offer to a specific job seeker for one of the employer's active job posts. Duplicate offers to the same seeker for the same post are rejected.
     *
     * @bodyParam job_seeker_id string required The target job seeker's user ID. Example: 664f1a2b3c4d5e6f7a8b9c0e
     * @bodyParam job_post_id string required The job post ID (must belong to the authenticated employer). Example: 664f1a2b3c4d5e6f7a8b9c0d
     * @bodyParam message string required Personalised offer message. Max 1000 chars. Example: We think you'd be a great fit for this role.
     *
     * @response 201 {
     *   "message": "Direct offer sent successfully",
     *   "offer": {
     *     "id": "664f1a2b3c4d5e6f7a8b9c0f",
     *     "employer_id": "664f1a2b3c4d5e6f7a8b9c0a",
     *     "job_seeker_id": "664f1a2b3c4d5e6f7a8b9c0e",
     *     "job_post_id": "664f1a2b3c4d5e6f7a8b9c0d",
     *     "message": "We think you'd be a great fit.",
     *     "status": "pending"
     *   }
     * }
     * @response 403 { "message": "Forbidden" }
     * @response 404 { "message": "Job post not found" }
     * @response 409 { "message": "A direct offer has already been sent to this job seeker for this job post" }
     */
    public function store(Request $request)
    {
        $employer = $request->user();

        $validator = Validator::make($request->all(), [
            'job_seeker_id' => 'required|string',
            'job_post_id'   => 'required|string',
            'message'       => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Verify the job post belongs to the authenticated employer
        try {
            $jobPost = JobPost::findOrFail($data['job_post_id']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Job post not found'], 404);
        }

        if ((string) $jobPost->employer_id !== (string) $employer->_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Verify the target user exists and has the employee role
        try {
            $jobSeeker = User::findOrFail($data['job_seeker_id']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Job seeker not found'], 422);
        }

        if (!$jobSeeker->hasRole('employee')) {
            return response()->json(['message' => 'Target user is not a job seeker'], 422);
        }

        // Check for duplicate offer
        $existing = DirectOffer::where('employer_id', $employer->_id)
            ->where('job_post_id', $data['job_post_id'])
            ->where('job_seeker_id', $data['job_seeker_id'])
            ->first();

        if ($existing) {
            return response()->json(['message' => 'A direct offer has already been sent to this job seeker for this job post'], 409);
        }

        $offer = DirectOffer::create([
            'employer_id'   => $employer->_id,
            'job_seeker_id' => $data['job_seeker_id'],
            'job_post_id'   => $data['job_post_id'],
            'message'       => $data['message'],
            'status'        => 'pending',
        ]);

        return response()->json([
            'message' => 'Direct offer sent successfully',
            'offer'   => $offer->load(['jobSeeker', 'jobPost']),
        ], 201);
    }

    /**
     * Sent offers
     *
     * Paginated list of direct offers sent by the authenticated employer, including job seeker name and job post title.
     *
     * @response 200 {
     *   "offers": {
     *     "data": [
     *       {
     *         "id": "664f1a2b3c4d5e6f7a8b9c0f",
     *         "status": "pending",
     *         "message": "We think you'd be a great fit.",
     *         "job_seeker_name": "Jane Smith",
     *         "job_post_title": "Senior Laravel Developer"
     *       }
     *     ],
     *     "current_page": 1, "per_page": 15, "total": 1, "total_pages": 1, "next_page": null, "prev_page": null
     *   }
     * }
     */
    public function indexSent(Request $request)
    {
        $employer = $request->user();

        $offers = DirectOffer::with(['jobSeeker', 'jobPost'])
            ->where('employer_id', $employer->_id)
            ->orderBy('created_at', 'desc')
            ->paginate(15)
            ->through(function ($offer) {
                return array_merge($offer->toArray(), [
                    'job_seeker_name' => $offer->jobSeeker->name ?? null,
                    'job_post_title'  => $offer->jobPost->title ?? null,
                ]);
            });

        return response()->json(['offers' => $offers]);
    }

    /**
     * Received offers
     *
     * Paginated list of direct offers received by the authenticated job seeker, including job post title and employer company name.
     *
     * @response 200 {
     *   "offers": {
     *     "data": [
     *       {
     *         "id": "664f1a2b3c4d5e6f7a8b9c0f",
     *         "status": "pending",
     *         "message": "We think you'd be a great fit.",
     *         "job_post_title": "Senior Laravel Developer",
     *         "employer_company_name": "Acme Corp"
     *       }
     *     ],
     *     "current_page": 1, "per_page": 15, "total": 1, "total_pages": 1, "next_page": null, "prev_page": null
     *   }
     * }
     */
    public function indexReceived(Request $request)
    {
        $jobSeeker = $request->user();

        $offers = DirectOffer::with(['employer', 'jobPost'])
            ->where('job_seeker_id', $jobSeeker->_id)
            ->orderBy('created_at', 'desc')
            ->paginate(15)
            ->through(function ($offer) {
                return array_merge($offer->toArray(), [
                    'job_post_title'       => $offer->jobPost->title ?? null,
                    'employer_company_name' => $offer->jobPost->company_name ?? null,
                ]);
            });

        return response()->json(['offers' => $offers]);
    }

    /**
     * Accept offer
     *
     * Accepts a direct offer and automatically creates a pending application for the related job post.
     *
     * @urlParam id string required The direct offer ID. Example: 664f1a2b3c4d5e6f7a8b9c0f
     *
     * @response 200 {
     *   "message": "Offer accepted successfully",
     *   "offer": { "id": "664f1a2b3c4d5e6f7a8b9c0f", "status": "accepted" }
     * }
     * @response 403 { "message": "Forbidden" }
     * @response 404 { "message": "Offer not found" }
     */
    public function accept(Request $request, $id)
    {
        $jobSeeker = $request->user();

        try {
            $offer = DirectOffer::findOrFail($id);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Offer not found'], 404);
        }

        if ((string) $offer->job_seeker_id !== (string) $jobSeeker->_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($offer->status !== 'pending') {
            return response()->json(['message' => 'This offer has already been resolved'], 409);
        }

        $offer->update(['status' => 'accepted']);

        $profile = $jobSeeker->jobSeekerProfile;
        Application::create([
            'user_id'      => $jobSeeker->_id,
            'job_post_id'  => $offer->job_post_id,
            'resume'       => $profile->cv_file_path ?? $profile->resume ?? null,
            'cover_letter' => $profile->default_cover_letter ?? null,
            'status'       => 'pending',
            'applied_at'   => now(),
        ]);

        return response()->json([
            'message' => 'Offer accepted successfully',
            'offer'   => $offer->fresh(),
        ]);
    }

    /**
     * Decline offer
     *
     * Declines a direct offer. The offer status is set to `declined`.
     *
     * @urlParam id string required The direct offer ID. Example: 664f1a2b3c4d5e6f7a8b9c0f
     *
     * @response 200 {
     *   "message": "Offer declined successfully",
     *   "offer": { "id": "664f1a2b3c4d5e6f7a8b9c0f", "status": "declined" }
     * }
     * @response 403 { "message": "Forbidden" }
     * @response 404 { "message": "Offer not found" }
     */
    public function decline(Request $request, $id)
    {
        $jobSeeker = $request->user();

        try {
            $offer = DirectOffer::findOrFail($id);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Offer not found'], 404);
        }

        if ((string) $offer->job_seeker_id !== (string) $jobSeeker->_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($offer->status !== 'pending') {
            return response()->json(['message' => 'This offer has already been resolved'], 409);
        }

        $offer->update(['status' => 'declined']);

        return response()->json([
            'message' => 'Offer declined successfully',
            'offer'   => $offer->fresh(),
        ]);
    }
}
