<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AnalyticsController;
use App\Http\Controllers\API\ApplicationController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\DirectOfferController;
use App\Http\Controllers\API\EmployerController;
use App\Http\Controllers\API\EmployerSearchController;
use App\Http\Controllers\API\JobMatchingController;
use App\Http\Controllers\API\JobPostController;
use App\Http\Controllers\API\CompanyProfileController;
use App\Http\Controllers\API\JobSeekerController;
use App\Http\Controllers\API\MatchedJobsController;
use App\Http\Controllers\API\ResumeCoachController;
use App\Http\Controllers\API\ResumeMatchingController;
use App\Http\Controllers\API\UserProfileController;
use App\Http\Controllers\API\UserSearchController;

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

// Public Company Routes
Route::get('companies', [CompanyProfileController::class, 'index']);
Route::get('companies/{id}', [CompanyProfileController::class, 'show']);

// Public User Search (talents/workers search)
Route::get('search/users', [UserSearchController::class, 'index']);

// Any authenticated user can view any user's full profile
Route::middleware('jwt.auth')->get('users/{userId}', [UserProfileController::class, 'show']);

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
    Route::post('resume/upload-and-analyze', [JobSeekerController::class, 'uploadAndAnalyze']);
    Route::get('applications', [ApplicationController::class, 'index']);
    Route::post('apply', [ApplicationController::class, 'store']);
    Route::delete('applications/{id}/withdraw', [ApplicationController::class, 'withdraw']);
    Route::get('jobs/search', [JobSeekerController::class, 'searchJobs']);

    // Matched Jobs
    Route::get('matched-jobs', [MatchedJobsController::class, 'index']);

    // Analytics
    Route::get('analytics', [AnalyticsController::class, 'seekerAnalytics']);

    // Direct Offers
    Route::get('offers', [DirectOfferController::class, 'indexReceived']);
    Route::post('offers/{id}/accept', [DirectOfferController::class, 'accept']);
    Route::post('offers/{id}/decline', [DirectOfferController::class, 'decline']);

    // Resume Matching AI
    Route::post('match-resume-to-jobs', [ResumeMatchingController::class, 'matchResume']);

    // Resume Coach AI
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

    // Company Profile
    Route::post('company', [CompanyProfileController::class, 'upsert']);
    Route::put('company', [CompanyProfileController::class, 'upsert']);

    // Application Management
    Route::get('jobs/{jobId}/applications', [ApplicationController::class, 'indexForEmployer']);
    Route::put('applications/{id}/status', [ApplicationController::class, 'updateStatus']);

    // Job Seeker Search
    Route::get('seekers', [EmployerSearchController::class, 'index']);
    Route::get('seekers/{userId}', [EmployerSearchController::class, 'show']);

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
    Route::post('{id}/approve', [EmployerController::class, 'approve']);
    Route::post('{id}/reject', [EmployerController::class, 'reject']);

    // Analytics
    Route::get('analytics', [AnalyticsController::class, 'adminAnalytics']);

    // User management
    Route::get('users', [UserProfileController::class, 'adminListAll']);
    Route::get('users/seekers', [UserProfileController::class, 'adminListSeekers']);
    Route::get('users/employers', [UserProfileController::class, 'adminListEmployers']);
});
