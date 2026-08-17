<?php

use App\Http\Controllers\API\AdminMeetingController;
use App\Http\Controllers\API\AdminReanalysisController;
use App\Http\Controllers\API\AdminReportingController;
use App\Http\Controllers\API\AnalyticsController;
use App\Http\Controllers\API\ApplicationController;
use App\Http\Controllers\API\AuditLogController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\BroadcastController;
use App\Http\Controllers\API\BulkOnboardingController;
use App\Http\Controllers\API\CompanyProfileController;
use App\Http\Controllers\API\DirectOfferController;
use App\Http\Controllers\API\EmployerController;
use App\Http\Controllers\API\EmployerSearchController;
use App\Http\Controllers\API\GoogleOAuthController;
use App\Http\Controllers\API\JobMatchingController;
use App\Http\Controllers\API\JobPostController;
use App\Http\Controllers\API\JobSeekerController;
use App\Http\Controllers\API\MatchedJobsController;
use App\Http\Controllers\API\MeetingActionController;
use App\Http\Controllers\API\MeetingController;
use App\Http\Controllers\API\MeetingNoteController;
use App\Http\Controllers\API\NotificationController;
use App\Http\Controllers\API\ResumeCoachController;
use App\Http\Controllers\API\ResumeMatchingController;
use App\Http\Controllers\API\TalentReportController;
use App\Http\Controllers\API\UserProfileController;
use App\Http\Controllers\API\UserSearchController;
use Illuminate\Support\Facades\Route;
//test
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('jwt.auth')->group(function () {
        Route::get('profile', [AuthController::class, 'profile']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('refresh', [AuthController::class, 'refresh']);
    });
});

// Public Job Post Routes
Route::get('jobs', [JobPostController::class, 'index']);
Route::get('jobs/{id}', [JobPostController::class, 'show']);
Route::get('jobs/search', [JobSeekerController::class, 'searchJobs']);

// Public Company Routes
Route::get('companies', [CompanyProfileController::class, 'index']);
Route::get('companies/{id}', [CompanyProfileController::class, 'show']);

// Public User Search (talents/workers search)
Route::get('search/users', [UserSearchController::class, 'index']);

// Public — any user (authenticated or not) can view any user's full profile
Route::get('users/{userId}', [UserProfileController::class, 'show']);

// Job Seeker Routes
Route::middleware(['jwt.auth', 'role:employee'])->prefix('job-seeker')->group(function () {
    Route::get('profile', [JobSeekerController::class, 'show']);

    // Profile section updates
    Route::put('profile/personal-info', [JobSeekerController::class, 'updatePersonalInfo']);
    Route::put('profile/career-info', [JobSeekerController::class, 'updateCareerInfo']);
    Route::put('profile/social-links', [JobSeekerController::class, 'updateSocialLinks']);

    // Skills
    Route::put('profile/skills', [JobSeekerController::class, 'updateSkills']);
    Route::delete('profile/skills', [JobSeekerController::class, 'deleteSkills']);

    // Education
    Route::put('profile/education', [JobSeekerController::class, 'updateEducation']);
    Route::delete('profile/education', [JobSeekerController::class, 'deleteEducation']);

    // Work Experience
    Route::put('profile/work-experience', [JobSeekerController::class, 'updateWorkExperience']);
    Route::delete('profile/work-experience', [JobSeekerController::class, 'deleteWorkExperience']);

    // Legacy endpoint (kept for backwards compatibility)
    Route::put('profile', [JobSeekerController::class, 'update']);

    Route::post('resume/upload', [JobSeekerController::class, 'uploadResume']);
    Route::delete('resume', [JobSeekerController::class, 'deleteResume']);
    Route::get('resume', [JobSeekerController::class, 'getResume']);
    Route::post('resume/upload-and-analyze', [JobSeekerController::class, 'uploadAndAnalyze']);
    Route::get('resume/analysis-status', [JobSeekerController::class, 'checkAnalysisStatus']);
    Route::post('resume/retry-analysis', [JobSeekerController::class, 'retryAnalysis']);
    Route::put('cover-letter', [JobSeekerController::class, 'saveDefaultCoverLetter']);
    Route::delete('cover-letter', [JobSeekerController::class, 'deleteDefaultCoverLetter']);
    Route::get('applications', [ApplicationController::class, 'index']);
    Route::post('apply', [ApplicationController::class, 'store']);
    Route::delete('applications/{id}/withdraw', [ApplicationController::class, 'withdraw']);
    Route::get('matched-jobs', [MatchedJobsController::class, 'index']);

    // Analytics
    Route::get('analytics', [AnalyticsController::class, 'seekerAnalytics']);

    // Direct Offers
    Route::get('offers', [DirectOfferController::class, 'indexReceived']);
    Route::post('offers/{id}/accept', [DirectOfferController::class, 'accept']);
    Route::post('offers/{id}/decline', [DirectOfferController::class, 'decline']);

    // Resume Matching AI
    Route::get('match-resume-to-jobs', [ResumeMatchingController::class, 'matchResume']);

    // Resume Coach AI
    Route::get('coach/sessions', [ResumeCoachController::class, 'listSessions']);
    Route::get('coach/sessions/{sessionId}', [ResumeCoachController::class, 'getSession']);
    Route::delete('coach/sessions/{sessionId}', [ResumeCoachController::class, 'deleteSession']);
    Route::post('coach/chat', [ResumeCoachController::class, 'chat']);
});

// Employer application — any authenticated user can apply to become an employer
// or check their application status (no role requirement)
Route::middleware('jwt.auth')->prefix('employer')->group(function () {
    Route::post('apply', [EmployerController::class, 'apply']);
    Route::get('status', [EmployerController::class, 'status']);
});

// Employer Routes — requires employer role + admin approval (is_employer = true)
Route::middleware(['jwt.auth', 'role:employer'])->prefix('employer')->group(function () {

    // Job Post Management
    Route::get('jobs', [JobPostController::class, 'myPosts']);
    Route::post('jobs', [JobPostController::class, 'store']);
    Route::put('jobs/{id}', [JobPostController::class, 'update']);
    Route::delete('jobs/{id}', [JobPostController::class, 'destroy']);
    Route::post('jobs/{id}/deactivate', [JobPostController::class, 'deactivate']);
    Route::post('jobs/{id}/activate', [JobPostController::class, 'activate']);

    // Company Profile
    Route::get('company', [CompanyProfileController::class, 'myProfile']);
    Route::post('company', [CompanyProfileController::class, 'updatePublic']);
    Route::put('company', [CompanyProfileController::class, 'updatePublic']);
    Route::put('company/private', [CompanyProfileController::class, 'updatePrivate']);
    Route::post('company/logo', [CompanyProfileController::class, 'uploadLogo']);
    Route::post('company/cover', [CompanyProfileController::class, 'uploadCoverImage']);

    // Application Management
    Route::get('jobs/{jobId}/applications', [ApplicationController::class, 'indexForEmployer']);
    Route::put('applications/{id}/status', [ApplicationController::class, 'updateStatus']);

    // Job Seeker Search
    Route::get('seekers', [EmployerSearchController::class, 'index']);
    Route::get('seekers/{userId}', [EmployerSearchController::class, 'showJobSeeker']);

    // Direct Offers
    Route::post('offers', [DirectOfferController::class, 'store']);
    Route::get('offers', [DirectOfferController::class, 'indexSent']);

    // Job Matching AI
    Route::post('match-candidates', [JobMatchingController::class, 'matchCandidates']);
    Route::post('jobs/{jobPostId}/match-candidates', [JobMatchingController::class, 'matchCandidatesToJobPost']);

    // Analytics
    Route::get('analytics', [AnalyticsController::class, 'employerAnalytics']);
});

// Admin Routes
Route::middleware(['jwt.auth', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('employers', [EmployerController::class, 'index']);
    Route::post('employers/{id}/approve', [EmployerController::class, 'approve']);
    Route::post('employers/{id}/reject', [EmployerController::class, 'reject']);

    // Analytics
    Route::get('analytics', [AnalyticsController::class, 'adminAnalytics']);

    // User management
    Route::get('users', [UserProfileController::class, 'adminListAll']);
    Route::get('users/seekers', [UserProfileController::class, 'adminListSeekers']);
    Route::get('users/employers', [UserProfileController::class, 'adminListEmployers']);

    // ── Business Intelligence Reports ───────────────────────────────
    Route::get('reports/churn',      [AdminReportingController::class, 'churn']);
    Route::get('reports/funnel',     [AdminReportingController::class, 'funnel']);
    Route::get('reports/pipeline',   [AdminReportingController::class, 'pipeline']);
    Route::get('reports/categories', [AdminReportingController::class, 'categories']);

    // Talent market report
    Route::get('reports/talent', [TalentReportController::class, 'index']);

    // Bulk B2B onboarding
    Route::post('onboarding/bulk', [BulkOnboardingController::class, 'store']);

    // Platform broadcast
    Route::post('broadcast', [BroadcastController::class, 'send']);

    // Manual CV re-analysis
    Route::post('users/{userId}/reanalyze', [AdminReanalysisController::class, 'reanalyze']);

    // Audit log viewer
    Route::get('audit-log', [AuditLogController::class, 'index']);

    // Meetings
    Route::get('meetings',      [AdminMeetingController::class, 'index']);
    Route::get('meetings/{id}', [AdminMeetingController::class, 'show']);
});

// Notifications — available to all authenticated users (no role restriction)
Route::middleware('jwt.auth')->prefix('notifications')->group(function () {
    Route::get('/',           [NotificationController::class, 'index']);
    Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/read-all',  [NotificationController::class, 'markAllRead']);
    Route::post('/{id}/read', [NotificationController::class, 'markRead']);
});

// Meetings — available to all authenticated users (employer or employee)
Route::middleware('jwt.auth')->prefix('meetings')->group(function () {
    Route::get('/',          [MeetingController::class, 'index']);
    Route::post('/',         [MeetingController::class, 'store']);
    Route::get('/upcoming',  [MeetingController::class, 'upcoming']);
    Route::get('/{id}',      [MeetingController::class, 'show']);

    Route::post('/{id}/accept',     [MeetingActionController::class, 'accept']);
    Route::post('/{id}/decline',    [MeetingActionController::class, 'decline']);
    Route::post('/{id}/cancel',     [MeetingActionController::class, 'cancel']);
    Route::post('/{id}/reschedule', [MeetingActionController::class, 'reschedule']);
    Route::post('/{id}/complete',   [MeetingActionController::class, 'complete']);

    Route::post('/{id}/notes', [MeetingNoteController::class, 'store']);
});

// Google OAuth — available to all authenticated users
Route::middleware('jwt.auth')->prefix('google')->group(function () {
    Route::get('/connect',       [GoogleOAuthController::class, 'connect']);
    Route::get('/callback',      [GoogleOAuthController::class, 'callback']);
    Route::get('/status',        [GoogleOAuthController::class, 'status']);
    Route::delete('/disconnect', [GoogleOAuthController::class, 'disconnect']);
});
