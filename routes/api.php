<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\EmployerController;
use App\Http\Controllers\API\JobSeekerController;

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:api')->group(function () {
        Route::get('profile', [AuthController::class, 'profile']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('refresh', [AuthController::class, 'refresh']);
    });
});

Route::middleware('auth:api')->group(function () {
    Route::post('employer/apply', [EmployerController::class, 'apply']);
    Route::get('employer/status', [EmployerController::class, 'status']);
});

// Job Seeker Routes
Route::middleware('auth:api')->prefix('job-seeker')->group(function () {
    Route::get('profile', [JobSeekerController::class, 'show']);
    Route::put('profile', [JobSeekerController::class, 'update']);
    Route::post('resume/upload', [JobSeekerController::class, 'uploadResume']);
    Route::get('applications', [JobSeekerController::class, 'applications']);
    Route::post('apply', [JobSeekerController::class, 'apply']);
    Route::delete('applications/{applicationId}/withdraw', [JobSeekerController::class, 'withdrawApplication']);
    Route::get('jobs/search', [JobSeekerController::class, 'searchJobs']);
});


// Employer Routes 
Route::middleware('auth:api')->group(function () {
    Route::post('employer/apply', [EmployerController::class, 'apply']);
    Route::get('employer/status', [EmployerController::class, 'status']);
});

// Admin Routes (existing)
//TODO make this a guard
Route::middleware('auth:api')->prefix('admin')->group(function () {
    Route::get('employers', [EmployerController::class, 'index']);
    Route::post('{id}/approve', [EmployerController::class, 'approve']);
    Route::post('{id}/reject', [EmployerController::class, 'reject']);
});
