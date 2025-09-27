<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\EmployerController;

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

Route::middleware(['auth:api', 'can:approve-employers'])->prefix('admin')->group(function () {
    Route::get('employers', [EmployerController::class, 'index']);
    Route::post('employers/{id}/approve', [EmployerController::class, 'approve']);
    Route::post('employers/{id}/reject', [EmployerController::class, 'reject']);
});


