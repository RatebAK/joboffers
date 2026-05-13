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
     * Employer: send a direct offer to a job seeker.
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
     * Employer: paginated list of sent offers with job seeker name and status.
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
     * Job seeker: paginated list of received offers with job post title and employer company name.
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
     * Job seeker: accept a direct offer and create an application.
     * @urlParam id string required The direct offer ID. Example: 6a04ca4809826695330cc475
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

        $offer->update(['status' => 'accepted']);

        Application::create([
            'user_id'    => $jobSeeker->_id,
            'job_post_id' => $offer->job_post_id,
            'status'     => 'pending',
            'applied_at' => now(),
        ]);

        return response()->json([
            'message' => 'Offer accepted successfully',
            'offer'   => $offer->fresh(),
        ]);
    }

    /**
     * Job seeker: decline a direct offer.
     * @urlParam id string required The direct offer ID. Example: 6a04ca4809826695330cc475
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

        $offer->update(['status' => 'declined']);

        return response()->json([
            'message' => 'Offer declined successfully',
            'offer'   => $offer->fresh(),
        ]);
    }
}
