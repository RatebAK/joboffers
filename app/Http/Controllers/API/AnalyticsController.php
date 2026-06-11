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
        try {
            // Count users by role using PHP filtering (more reliable than MongoDB array queries)
            $allUsers = User::all();
            $usersByRole = [
                'employee' => $allUsers->filter(fn($u) => in_array('employee', $u->roles ?? []))->count(),
                'employer' => $allUsers->filter(fn($u) => in_array('employer', $u->roles ?? []))->count(),
                'admin'    => $allUsers->filter(fn($u) => in_array('admin', $u->roles ?? []))->count(),
            ];

            $jobsActive = JobPost::where('is_active', true)->count();
            $jobsTotal  = JobPost::count();

            // Group applications by status using PHP
            $allApplications = Application::all();
            $appStatusMap = $allApplications->groupBy('status')->map->count()->toArray();

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
                : 0.0;

            // Top skills from job posts (using PHP aggregation to avoid segfault)
            $activeJobs = JobPost::where('is_active', true)->get();
            $skillCounts = [];
            foreach ($activeJobs as $job) {
                $skills = array_merge((array)($job->roles ?? []), (array)($job->tags ?? []));
                foreach ($skills as $skill) {
                    if (!empty($skill)) {
                        $skillCounts[$skill] = ($skillCounts[$skill] ?? 0) + 1;
                    }
                }
            }
            arsort($skillCounts);
            $topSkills = array_slice(array_map(fn($skill, $count) => ['_id' => $skill, 'count' => $count], 
                array_keys($skillCounts), $skillCounts), 0, 10, true);
            $topSkills = array_values($topSkills);

            // Registrations by month (PHP aggregation)
            $registrationsByMonth = $allUsers->groupBy(function($user) {
                return $user->created_at ? $user->created_at->format('Y-m') : 'unknown';
            })->map(fn($group) => ['_id' => $group->first()->created_at->format('Y-m'), 'count' => $group->count()])
            ->sortByDesc('_id')
            ->take(12)
            ->values()
            ->toArray();

            // Top employers by job post count (PHP aggregation)
            $allJobs = JobPost::all();
            $jobsByEmployer = $allJobs->groupBy('employer_id')->map->count()->sortDesc()->take(10);
            $topEmployersData = [];
            foreach ($jobsByEmployer as $employerId => $jobCount) {
                $employer = User::find($employerId);
                if ($employer) {
                    $topEmployersData[] = [
                        'employer_id'   => (string)$employerId,
                        'employer_name' => $employer->name,
                        'job_count'     => $jobCount,
                    ];
                }
            }

            // Average ATS score
            $avgAtsScore = JobSeekerProfile::whereNotNull('ats_score')->avg('ats_score') ?? 0;

            return response()->json([
                'users'               => [
                    'total'   => $allUsers->count(),
                    'by_role' => $usersByRole,
                ],
                'jobs'                => [
                    'total_active' => $jobsActive,
                    'total_all'    => $jobsTotal,
                ],
                'applications'        => [
                    'total'     => $allApplications->count(),
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
        } catch (\Exception $e) {
            \Log::error('Admin analytics error: ' . $e->getMessage());
            return response()->json(['message' => 'Error generating analytics'], 500);
        }
    }

    /**
     * Employer analytics - scoped to employer's jobs
     */
    public function employerAnalytics(Request $request): JsonResponse
    {
        try {
            $employerId = (string)auth()->id();

            $jobsTotal    = JobPost::where('employer_id', $employerId)->count();
            $jobsActive   = JobPost::where('employer_id', $employerId)->where('is_active', true)->count();
            $jobsInactive = $jobsTotal - $jobsActive;

            $employerJobIds = JobPost::where('employer_id', $employerId)->pluck('_id')->map(fn($id) => (string) $id)->toArray();

            // Group applications by status using PHP
            $applications = Application::whereIn('job_post_id', $employerJobIds)->get();
            $applicationsTotal = $applications->count();
            $appStatusMap = $applications->groupBy('status')->map->count()->toArray();

            // Applications per job using PHP
            $applicationsPerJobData = $applications->groupBy('job_post_id')->map(function ($apps, $jobId) {
                $job = JobPost::find($jobId);
                return [
                    'job_id'    => (string)$jobId,
                    'job_title' => $job ? $job->title : 'Unknown',
                    'count'     => $apps->count(),
                ];
            })->values()->toArray();

            $offersSent     = DirectOffer::where('employer_id', $employerId)->count();
            $offersAccepted = DirectOffer::where('employer_id', $employerId)->where('status', 'accepted')->count();
            $offersDeclined = DirectOffer::where('employer_id', $employerId)->where('status', 'declined')->count();

            // Top applicant skills
            $applicantIds = $applications->pluck('user_id')->unique()->toArray();
            $skillCounts = [];
            if (!empty($applicantIds)) {
                $profiles = JobSeekerProfile::whereIn('user_id', $applicantIds)
                    ->whereNotNull('ai_skills')
                    ->get();
                
                foreach ($profiles as $profile) {
                    $skills = (array)($profile->ai_skills ?? []);
                    foreach ($skills as $skill) {
                        if (!empty($skill)) {
                            $skillCounts[$skill] = ($skillCounts[$skill] ?? 0) + 1;
                        }
                    }
                }
            }
            arsort($skillCounts);
            $topApplicantSkills = array_slice($skillCounts, 0, 10, true);

            // Average applicant ATS score
            $avgApplicantAts = 0;
            if (!empty($applicantIds)) {
                $avgApplicantAts = JobSeekerProfile::whereIn('user_id', $applicantIds)->whereNotNull('ats_score')->avg('ats_score') ?? 0;
            }

            // Recent applications
            $recentApplications = $applications->sortByDesc('created_at')->take(5)->map(function ($app) {
                $job  = JobPost::find($app->job_post_id);
                $user = User::find($app->user_id);
                return [
                    'application_id' => (string) $app->_id,
                    'job_title'      => $job ? $job->title : 'Unknown',
                    'applicant_name' => $user ? $user->name : 'Unknown',
                    'status'         => $app->status,
                    'created_at'     => $app->created_at,
                ];
            })->values();

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
        } catch (\Exception $e) {
            \Log::error('Employer analytics error: ' . $e->getMessage());
            return response()->json(['message' => 'Error generating analytics'], 500);
        }
    }

    /**
     * Job seeker analytics - personal statistics
     */
    public function seekerAnalytics(Request $request): JsonResponse
    {
        try {
            $seekerId = (string)auth()->id();

            // Get all applications for this seeker
            $applications = Application::where('user_id', $seekerId)->get();
            $applicationsTotal = $applications->count();

            // Group by status using PHP
            $appStatusMap = $applications->groupBy('status')->map->count()->toArray();

            $offersReceived = DirectOffer::where('job_seeker_id', $seekerId)->count();
            $offersAccepted = DirectOffer::where('job_seeker_id', $seekerId)->where('status', 'accepted')->count();
            $offersDeclined = DirectOffer::where('job_seeker_id', $seekerId)->where('status', 'declined')->count();

            $profile = JobSeekerProfile::where('user_id', $seekerId)->first();

            $atsScore = [
                'current'     => $profile ? ($profile->ats_score ?? null) : null,
                'analyzed_at' => $profile ? ($profile->ai_analyzed_at ?? null) : null,
            ];

            // Matched jobs count
            $appliedJobIds = $applications->pluck('job_post_id')->map(fn($id) => (string)$id)->toArray();
            $matchedJobsCount = JobPost::where('is_active', true)
                ->whereNotIn('_id', $appliedJobIds)
                ->count();

            // Top applied categories using PHP
            $appliedJobs = JobPost::whereIn('_id', $appliedJobIds)->get();
            $topAppliedCategories = $appliedJobs
                ->filter(fn($job) => !empty($job->category))
                ->pluck('category')
                ->countBy()
                ->sortDesc()
                ->take(5)
                ->toArray();

            // Recent applications
            $recentApplications = $applications->sortByDesc('created_at')->take(5)->map(function ($app) {
                $job = JobPost::find($app->job_post_id);
                return [
                    'application_id' => (string) $app->_id,
                    'job_title'      => $job ? $job->title : 'Unknown',
                    'company_name'   => $job ? $job->company_name : 'Unknown',
                    'status'         => $app->status,
                    'created_at'     => $app->created_at,
                ];
            })->values();

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
        } catch (\Exception $e) {
            \Log::error('Seeker analytics error: ' . $e->getMessage());
            return response()->json(['message' => 'Error generating analytics'], 500);
        }
    }
}
