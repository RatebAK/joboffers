<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\ApplicationController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\DirectOfferController;
use App\Http\Controllers\API\EmployerController;
use App\Http\Controllers\API\EmployerSearchController;
use App\Http\Controllers\API\JobPostController;
use App\Http\Controllers\API\JobSeekerController;

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

// Job Seeker Routes
Route::middleware(['jwt.auth', 'role:employee'])->prefix('job-seeker')->group(function () {
    Route::get('profile', [JobSeekerController::class, 'show']);
    Route::put('profile', [JobSeekerController::class, 'update']);
    Route::post('resume/upload', [JobSeekerController::class, 'uploadResume']);
    Route::post('resume/upload-and-analyze', [JobSeekerController::class, 'uploadAndAnalyze']);
    Route::get('applications', [ApplicationController::class, 'index']);
    Route::post('apply', [ApplicationController::class, 'store']);
    Route::delete('applications/{id}/withdraw', [ApplicationController::class, 'withdraw']);
    Route::get('jobs/search', [JobSeekerController::class, 'searchJobs']);

    // Direct Offers
    Route::get('offers', [DirectOfferController::class, 'indexReceived']);
    Route::post('offers/{id}/accept', [DirectOfferController::class, 'accept']);
    Route::post('offers/{id}/decline', [DirectOfferController::class, 'decline']);
});

// Employer Routes 
Route::middleware(['jwt.auth', 'role:employer'])->prefix('employer')->group(function () {
    Route::post('apply', [EmployerController::class, 'apply']);
    Route::get('status', [EmployerController::class, 'status']);

    // Job Post Management
    Route::get('jobs', [JobPostController::class, 'myPosts']);
    Route::post('jobs', [JobPostController::class, 'store']);
    Route::put('jobs/{id}', [JobPostController::class, 'update']);
    Route::delete('jobs/{id}', [JobPostController::class, 'destroy']);
    Route::post('jobs/{id}/deactivate', [JobPostController::class, 'deactivate']);

    // Application Management
    Route::get('jobs/{jobId}/applications', [ApplicationController::class, 'indexForEmployer']);
    Route::put('applications/{id}/status', [ApplicationController::class, 'updateStatus']);

    // Job Seeker Search
    Route::get('seekers', [EmployerSearchController::class, 'index']);
    Route::get('seekers/{userId}', [EmployerSearchController::class, 'show']);

    // Direct Offers
    Route::post('offers', [DirectOfferController::class, 'store']);
    Route::get('offers', [DirectOfferController::class, 'indexSent']);
});

// Admin Routes
Route::middleware(['jwt.auth', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('employers', [EmployerController::class, 'index']);
    Route::post('{id}/approve', [EmployerController::class, 'approve']);
    Route::post('{id}/reject', [EmployerController::class, 'reject']);
});
