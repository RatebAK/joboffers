<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Employer;
use App\Models\JobPost;
use App\Models\User;
use App\Services\ChurnReportService;
use App\Services\ConversionFunnelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AdminReportingController extends Controller
{
    /**
     * Churn & re-engagement report
     *
     * [NEW API] Returns inactive employers (no posts within window_days) and job seekers who have a
     * CV on file but have never applied. Use `format=csv` to download a CSV for email campaigns.
     *
     * @queryParam window_days integer Days to look back for employer inactivity. Valid: 30, 60, 90 (default: 30). Example: 30
     * @queryParam format string Set to `csv` to download as CSV. Example: csv
     *
     * @response 200 {
     *   "window_days": 30,
     *   "employers": [
     *     { "user_id": "abc", "name": "Acme Corp", "email": "hr@acme.com", "registered_at": "2024-01-01T00:00:00Z", "last_post_date": null, "total_posts": 0 }
     *   ],
     *   "seekers": [
     *     { "user_id": "xyz", "name": "Jane Smith", "email": "jane@email.com", "registered_at": "2024-01-01T00:00:00Z", "cv_uploaded_at": "2024-02-01T00:00:00Z", "ats_score": 72 }
     *   ]
     * }
     */
    public function churn(Request $request, ChurnReportService $service): Response|JsonResponse
    {
        $windowDays = (int) $request->query('window_days', 30);
        if (! in_array($windowDays, [30, 60, 90])) {
            $windowDays = 30;
        }

        $employers = $service->getChurnedEmployers($windowDays);
        $seekers   = $service->getChurnedSeekers();

        if ($request->query('format') === 'csv') {
            $csv = $service->toCsv($employers, $seekers);
            return response($csv, 200, [
                'Content-Type'        => 'text/csv',
                'Content-Disposition' => 'attachment; filename="churn_report.csv"',
            ]);
        }

        return response()->json([
            'window_days' => $windowDays,
            'employers'   => $employers,
            'seekers'     => $seekers,
        ]);
    }

    /**
     * Conversion funnel metrics
     *
     * [NEW API] Shows how job seekers progress through the registration → CV upload → application → hired funnel,
     * including drop-off percentages at each stage.
     *
     * @response 200 {
     *   "stages": [
     *     { "stage": "registered",  "count": 500, "drop_off_pct": null },
     *     { "stage": "cv_uploaded", "count": 320, "drop_off_pct": 36.0 },
     *     { "stage": "applied",     "count": 180, "drop_off_pct": 43.75 },
     *     { "stage": "hired",       "count": 42,  "drop_off_pct": 76.67 }
     *   ]
     * }
     */
    public function funnel(ConversionFunnelService $service): JsonResponse
    {
        return response()->json($service->compute());
    }

    /**
     * Employer approval pipeline report
     *
     * [NEW API] Shows all pending employer applications with wait times and an estimated lost revenue figure.
     * Useful for prioritising the approval queue.
     *
     * @queryParam daily_revenue_per_employer number Daily revenue estimate per unapproved employer (default: 10). Example: 10
     *
     * @response 200 {
     *   "pending_count": 5,
     *   "avg_wait_days": 3.2,
     *   "daily_revenue_per_employer": 10,
     *   "estimated_lost_revenue": 160.0,
     *   "employers": [
     *     { "user_id": "abc", "name": "John Doe", "email": "john@corp.com", "submitted_at": "2024-01-10T00:00:00Z", "days_waiting": 4 }
     *   ]
     * }
     */
    public function pipeline(Request $request): JsonResponse
    {
        $rate = (float) $request->query('daily_revenue_per_employer', 10);
        if ($rate <= 0) {
            $rate = 10;
        }

        $pending = Employer::where('status', Employer::STATUS_PENDING)->get();
        $now     = now();

        $employers = $pending->map(function (Employer $employer) use ($now) {
            $user        = User::find($employer->user_id);
            $daysWaiting = $employer->created_at
                ? (int) ceil($employer->created_at->diffInDays($now))
                : 0;

            return [
                'user_id'      => (string) $employer->user_id,
                'name'         => $user?->name,
                'email'        => $user?->email,
                'submitted_at' => $employer->created_at?->toISOString(),
                'days_waiting' => $daysWaiting,
            ];
        })->values();

        $pendingCount = $employers->count();
        $avgWaitDays  = $pendingCount > 0
            ? round($employers->avg('days_waiting'), 2)
            : 0.0;

        $estimatedLostRevenue = $pendingCount * $avgWaitDays * $rate;

        return response()->json([
            'pending_count'              => $pendingCount,
            'avg_wait_days'              => $avgWaitDays,
            'daily_revenue_per_employer' => $rate,
            'estimated_lost_revenue'     => round($estimatedLostRevenue, 2),
            'employers'                  => $employers,
        ]);
    }

    /**
     * Top performing job categories
     *
     * [NEW API] Returns job categories ranked by application count (default) or post count.
     * Useful for identifying where to focus sales outreach.
     *
     * @queryParam sort_by string Sort by `applications` (default) or `posts`. Example: applications
     * @queryParam limit integer Number of categories to return (1-50, default: 10). Example: 10
     *
     * @response 200 {
     *   "sort_by": "applications",
     *   "categories": [
     *     { "category": "Technology", "post_count": 42, "application_count": 310 }
     *   ]
     * }
     */
    public function categories(Request $request): JsonResponse
    {
        $sortBy = $request->query('sort_by', 'applications');
        $limit  = min(50, max(1, (int) $request->query('limit', 10)));

        if (! in_array($sortBy, ['applications', 'posts'])) {
            $sortBy = 'applications';
        }

        $allPosts        = JobPost::all();
        $allApplications = Application::all();

        // Index application counts per job post
        $appCountByPost = $allApplications->countBy(fn ($a) => (string) $a->job_post_id);

        // Aggregate by category
        $categoryMap = [];
        foreach ($allPosts as $post) {
            $cat = $post->category ?? 'Uncategorized';
            if (! isset($categoryMap[$cat])) {
                $categoryMap[$cat] = ['category' => $cat, 'post_count' => 0, 'application_count' => 0];
            }
            $categoryMap[$cat]['post_count']++;
            $categoryMap[$cat]['application_count'] += $appCountByPost->get((string) $post->_id, 0);
        }

        $sorted = collect(array_values($categoryMap));

        $sorted = $sortBy === 'posts'
            ? $sorted->sortByDesc('post_count')
            : $sorted->sortByDesc('application_count');

        return response()->json([
            'sort_by'    => $sortBy,
            'categories' => $sorted->take($limit)->values(),
        ]);
    }
}
