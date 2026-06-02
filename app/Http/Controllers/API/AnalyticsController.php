<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\CompanyProfile;
use App\Models\DirectOffer;
use App\Models\Employer;
use App\Models\JobPost;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    /**
     * Admin analytics - platform-wide statistics
     */
    public function adminAnalytics(): JsonResponse
    {
        $usersByRole = [
            'employee' => User::where('roles', 'employee')->count(),
            'employer' => User::where('roles', 'employer')->count(),
            'admin'    => User::where('roles', 'admin')->count(),
        ];

        $jobsActive = JobPost::where('is_active', true)->count();
        $jobsTotal  = JobPost::count();

        $applicationsByStatus = Application::raw(function ($collection) {
            return $collection->aggregate([
                ['$group' => ['_id' => '$status', 'count' => ['$sum' => 1]]],
            ]);
        })->toArray();

        $appStatusMap = [];
        foreach ($applicationsByStatus as $item) {
            $appStatusMap[$item->_id] = $item->count;
        }

        $offersTotal = DirectOffer::count();

        $companiesTotal = CompanyProfile::count();

        $employerApprovals = [
            'pending'  => Employer::where('status', 'pending')->count(),
            'approved' => Employer::where('status', 'approved')->count(),
            'rejected' => Employer::where('status', 'rejected')->count(),
        ];

        $totalProcessed = $employerApprovals['approved'] + $employerApprovals['rejected'];
        $employerApprovals['approval_rate'] = $totalProcessed > 0
            ? round(($employerApprovals['approved'] / $totalProcessed) * 100, 2)
            : 0;

        // Top skills from job posts
        $topSkills = JobPost::raw(function ($collection) {
            return $collection->aggregate([
                ['$match' => ['is_active' => true]],
                ['$project' => ['skills' => ['$concatArrays' => [['$ifNull' => ['$roles', []]], ['$ifNull' => ['$tags', []]]]]]],
                ['$unwind' => '$skills'],
                ['$group' => ['_id' => '$skills', 'count' => ['$sum' => 1]]],
                ['$sort' => ['count' => -1]],
                ['$limit' => 10],
            ]);
        })->toArray();

        // Registrations by month (last 12 months)
        $registrationsByMonth = User::raw(function ($collection) {
            return $collection->aggregate([
                [
                    '$group' => [
                        '_id'   => ['$dateToString' => ['format' => '%Y-%m', 'date' => '$created_at']],
                        'count' => ['$sum' => 1],
                    ],
                ],
                ['$sort' => ['_id' => -1]],
                ['$limit' => 12],
            ]);
        })->toArray();

        // Top employers by job post count
        $topEmployers = JobPost::raw(function ($collection) {
            return $collection->aggregate([
                ['$group' => ['_id' => '$employer_id', 'job_count' => ['$sum' => 1]]],
                ['$sort' => ['job_count' => -1]],
                ['$limit' => 10],
            ]);
        })->toArray();

        $topEmployersData = [];
        foreach ($topEmployers as $item) {
            $employer = User::find($item->_id);
            if ($employer) {
                $topEmployersData[] = [
                    'employer_id'   => $item->_id,
                    'employer_name' => $employer->name,
                    'job_count'     => $item->job_count,
                ];
            }
        }

        // Average ATS score
        $avgAtsScore = JobSeekerProfile::whereNotNull('ats_score')->avg('ats_score') ?? 0;

        return response()->json([
            'users'               => [
                'total'   => User::count(),
                'by_role' => $usersByRole,
            ],
            'jobs'                => [
                'total_active' => $jobsActive,
                'total_all'    => $jobsTotal,
            ],
            'applications'        => [
                'total'     => Application::count(),
                'by_status' => $appStatusMap,
            ],
            'offers'              => [
                'total' => $offersTotal,
            ],
            'companies'           => [
                'total' => $companiesTotal,
            ],
            'employer_approvals'  => $employerApprovals,
            'top_skills'          => $topSkills,
            'registrations_by_month' => $registrationsByMonth,
            'top_employers'       => $topEmployersData,
            'avg_ats_score'       => round($avgAtsScore, 2),
        ]);
    }

    /**
     * Employer analytics - scoped to employer's jobs
     */
    public function employerAnalytics(Request $request): JsonResponse
    {
        $employerId = auth()->id();

        $jobsTotal    = JobPost::where('employer_id', $employerId)->count();
        $jobsActive   = JobPost::where('employer_id', $employerId)->where('is_active', true)->count();
        $jobsInactive = $jobsTotal - $jobsActive;

        $employerJobIds = JobPost::where('employer_id', $employerId)->pluck('_id')->map(fn($id) => (string) $id)->toArray();

        $applicationsTotal = Application::whereIn('job_post_id', $employerJobIds)->count();

        $applicationsByStatus = Application::raw(function ($collection) use ($employerJobIds) {
            return $collection->aggregate([
                ['$match' => ['job_post_id' => ['$in' => $employerJobIds]]],
                ['$group' => ['_id' => '$status', 'count' => ['$sum' => 1]]],
            ]);
        })->toArray();

        $appStatusMap = [];
        foreach ($applicationsByStatus as $item) {
            $appStatusMap[$item->_id] = $item->count;
        }

        $applicationsPerJob = Application::raw(function ($collection) use ($employerJobIds) {
            return $collection->aggregate([
                ['$match' => ['job_post_id' => ['$in' => $employerJobIds]]],
                ['$group' => ['_id' => '$job_post_id', 'count' => ['$sum' => 1]]],
            ]);
        })->toArray();

        $applicationsPerJobData = [];
        foreach ($applicationsPerJob as $item) {
            $job = JobPost::find($item->_id);
            $applicationsPerJobData[] = [
                'job_id'    => $item->_id,
                'job_title' => $job ? $job->title : 'Unknown',
                'count'     => $item->count,
            ];
        }

        $offersSent     = DirectOffer::where('employer_id', $employerId)->count();
        $offersAccepted = DirectOffer::where('employer_id', $employerId)->where('status', 'accepted')->count();
        $offersDeclined = DirectOffer::where('employer_id', $employerId)->where('status', 'declined')->count();

        // Top applicant skills
        $applicantIds = Application::whereIn('job_post_id', $employerJobIds)->pluck('user_id')->unique()->toArray();
        $topApplicantSkills = JobSeekerProfile::whereIn('user_id', $applicantIds)
            ->whereNotNull('ai_skills')
            ->get()
            ->pluck('ai_skills')
            ->flatten()
            ->countBy()
            ->sortDesc()
            ->take(10)
            ->toArray();

        // Average applicant ATS score
        $avgApplicantAts = JobSeekerProfile::whereIn('user_id', $applicantIds)->whereNotNull('ats_score')->avg('ats_score') ?? 0;

        // Recent applications
        $recentApplications = Application::whereIn('job_post_id', $employerJobIds)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($app) {
                $job  = JobPost::find($app->job_post_id);
                $user = User::find($app->user_id);
                return [
                    'application_id' => (string) $app->_id,
                    'job_title'      => $job ? $job->title : 'Unknown',
                    'applicant_name' => $user ? $user->name : 'Unknown',
                    'status'         => $app->status,
                    'created_at'     => $app->created_at,
                ];
            });

        return response()->json([
            'jobs'                 => [
                'total'    => $jobsTotal,
                'active'   => $jobsActive,
                'inactive' => $jobsInactive,
            ],
            'applications'         => [
                'total'     => $applicationsTotal,
                'by_status' => $appStatusMap,
            ],
            'applications_per_job' => $applicationsPerJobData,
            'offers'               => [
                'total_sent' => $offersSent,
                'accepted'   => $offersAccepted,
                'declined'   => $offersDeclined,
            ],
            'top_applicant_skills' => $topApplicantSkills,
            'avg_applicant_ats_score' => round($avgApplicantAts, 2),
            'recent_applications'  => $recentApplications,
        ]);
    }

    /**
     * Job seeker analytics - personal statistics
     */
    public function seekerAnalytics(Request $request): JsonResponse
    {
        $seekerId = auth()->id();

        $applicationsTotal = Application::where('user_id', $seekerId)->count();

        $applicationsByStatus = Application::raw(function ($collection) use ($seekerId) {
            return $collection->aggregate([
                ['$match' => ['user_id' => $seekerId]],
                ['$group' => ['_id' => '$status', 'count' => ['$sum' => 1]]],
            ]);
        })->toArray();

        $appStatusMap = [];
        foreach ($applicationsByStatus as $item) {
            $appStatusMap[$item->_id] = $item->count;
        }

        $offersReceived = DirectOffer::where('job_seeker_id', $seekerId)->count();
        $offersAccepted = DirectOffer::where('job_seeker_id', $seekerId)->where('status', 'accepted')->count();
        $offersDeclined = DirectOffer::where('job_seeker_id', $seekerId)->where('status', 'declined')->count();

        $profile = JobSeekerProfile::where('user_id', $seekerId)->first();

        $atsScore = [
            'current'     => $profile->ats_score ?? null,
            'analyzed_at' => $profile->ai_analyzed_at ?? null,
        ];

        // Matched jobs count
        $appliedJobIds = Application::where('user_id', $seekerId)->pluck('job_post_id')->toArray();
        $matchedJobsCount = JobPost::where('is_active', true)
            ->whereNotIn('_id', $appliedJobIds)
            ->count();

        // Top applied categories
        $appliedJobIds = Application::where('user_id', $seekerId)->pluck('job_post_id')->toArray();
        $topAppliedCategories = JobPost::whereIn('_id', $appliedJobIds)
            ->whereNotNull('category')
            ->get()
            ->pluck('category')
            ->countBy()
            ->sortDesc()
            ->take(5)
            ->toArray();

        // Recent applications
        $recentApplications = Application::where('user_id', $seekerId)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($app) {
                $job = JobPost::find($app->job_post_id);
                return [
                    'application_id' => (string) $app->_id,
                    'job_title'      => $job ? $job->title : 'Unknown',
                    'company_name'   => $job ? $job->company_name : 'Unknown',
                    'status'         => $app->status,
                    'created_at'     => $app->created_at,
                ];
            });

        return response()->json([
            'applications'        => [
                'total'     => $applicationsTotal,
                'by_status' => $appStatusMap,
            ],
            'offers'              => [
                'total_received' => $offersReceived,
                'accepted'       => $offersAccepted,
                'declined'       => $offersDeclined,
            ],
            'ats_score'           => $atsScore,
            'matched_jobs_count'  => $matchedJobsCount,
            'top_applied_categories' => $topAppliedCategories,
            'recent_applications' => $recentApplications,
        ]);
    }
}
