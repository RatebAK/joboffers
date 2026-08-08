<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\BulkOnboardingRequest;
use App\Services\BulkOnboardingService;
use Illuminate\Http\JsonResponse;

class BulkOnboardingController extends Controller
{
    /**
     * Bulk employer onboarding via CSV upload
     *
     * [NEW API] Accepts a CSV file of employer accounts and creates pending User + Employer records for each
     * valid row, dispatching invite emails asynchronously.
     *
     * ## CSV Format
     * Required columns: `name`, `email`, `company_name`
     * Optional column: `partner_type` (values: `agency`, `university`, `enterprise`)
     *
     * Example CSV:
     * ```
     * name,email,company_name,partner_type
     * Acme HR,hr@acme.com,Acme Corp,agency
     * State University,careers@university.edu,State University,university
     * ```
     *
     * Rows missing required columns or with duplicate emails are skipped and reported in the response.
     *
     * @bodyParam file file required CSV file, max 2 MB. Required columns: name, email, company_name.
     *
     * @response 200 {
     *   "total_rows": 5,
     *   "created": 4,
     *   "skipped": 1,
     *   "skipped_rows": [
     *     { "email": "existing@example.com", "reason": "email_exists" }
     *   ]
     * }
     * @response 422 { "errors": { "file": ["File must be a valid CSV."] } }
     */
    public function store(BulkOnboardingRequest $request, BulkOnboardingService $service): JsonResponse
    {
        $actor = $request->user();

        try {
            $result = $service->process(
                $request->file('file'),
                (string) $actor->_id,
                $actor->name
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }
}
