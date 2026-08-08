<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\TalentReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TalentReportController extends Controller
{
    /**
     * Anonymized talent market report
     *
     * [NEW API] Aggregates skills demand and ATS score statistics across all job seeker profiles.
     * All PII (name, email, phone, user_id, ai_contact fields) is excluded — safe to share externally.
     *
     * Requires at least 5 profiles matching the filter; returns 422 otherwise.
     *
     * @queryParam limit integer Top N skills to return (default: 20, max: 100). Example: 20
     * @queryParam industry string Filter by job_roles value e.g. "Technology". Example: Technology
     * @queryParam format string Set to `csv` to download as CSV file. Example: csv
     *
     * @response 200 {
     *   "profile_count": 142,
     *   "top_skills": [
     *     { "skill": "PHP", "count": 87 },
     *     { "skill": "Laravel", "count": 64 }
     *   ],
     *   "ats_stats": {
     *     "average": 71.4,
     *     "median": 73.0,
     *     "minimum": 12,
     *     "maximum": 98
     *   }
     * }
     * @response 422 { "message": "Insufficient data for anonymized report" }
     */
    public function index(Request $request, TalentReportService $service): Response|JsonResponse
    {
        $limit    = min(100, max(1, (int) $request->query('limit', 20)));
        $industry = $request->query('industry');

        try {
            $data = $service->compute($limit, $industry ?: null);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($request->query('format') === 'csv') {
            $csv = $service->toCsv($data);
            return response($csv, 200, [
                'Content-Type'        => 'text/csv',
                'Content-Disposition' => 'attachment; filename="talent_report.csv"',
            ]);
        }

        return response()->json($data);
    }
}
