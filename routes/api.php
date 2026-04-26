<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\EmployerController;
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

// Job Seeker Routes
Route::middleware(['jwt.auth', 'role:employee'])->prefix('job-seeker')->group(function () {
    Route::get('profile', [JobSeekerController::class, 'show']);
    Route::put('profile', [JobSeekerController::class, 'update']);
    Route::post('resume/upload', [JobSeekerController::class, 'uploadResume']);
    Route::get('applications', [JobSeekerController::class, 'applications']);
    Route::post('apply', [JobSeekerController::class, 'apply']);
    Route::delete('applications/{applicationId}/withdraw', [JobSeekerController::class, 'withdrawApplication']);
    Route::get('jobs/search', [JobSeekerController::class, 'searchJobs']);
});

// Employer Routes 
Route::middleware(['jwt.auth', 'role:employer'])->prefix('employer')->group(function () {
    Route::post('apply', [EmployerController::class, 'apply']);
    Route::get('status', [EmployerController::class, 'status']);
});

// Admin Routes
Route::middleware(['jwt.auth', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('employers', [EmployerController::class, 'index']);
    Route::post('{id}/approve', [EmployerController::class, 'approve']);
    Route::post('{id}/reject', [EmployerController::class, 'reject']);
});
