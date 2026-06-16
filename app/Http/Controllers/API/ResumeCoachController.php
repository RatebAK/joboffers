<?php

namespace App\Http\Controllers\API;

use App\Exceptions\CvAnalysisException;
use App\Http\Controllers\Controller;
use App\Services\ResumeCoachService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ResumeCoachController extends Controller
{
    /**
     * Chat with AI resume coach
     *
     * Ask the AI coach for career advice, job market insights, resume tips, and more.
     *
     * @bodyParam message string required Your question or message to the coach. Max 1000 chars. Example: Give me advice on improving my resume
     *
     * @response 200 {
     *   "response": "To improve your resume, focus on quantifying your achievements..."
     * }
     * @response 422 { "errors": { "message": ["The message field is required."] } }
     * @response 502 { "message": "Resume coach service unavailable" }
     */
    public function chat(Request $request, ResumeCoachService $coachService)
    {
        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $response = $coachService->chat($request->input('message'));
        } catch (CvAnalysisException $e) {
            $statusCode = $e->getHttpStatusCode();

            if ($statusCode === 422) {
                return response()->json([
                    'message' => 'Resume coach request failed',
                    'reason' => $e->getMessage(),
                ], 422);
            }

            return response()->json([
                'message' => 'Resume coach service unavailable',
            ], 502);
        }

        return response()->json([
            'response' => $response,
        ], 200);
    }
}
