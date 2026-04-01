<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WebmasterTaskController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });

    Route::get('/roles', [RoleController::class, 'index']);

    Route::get('/dashboard/summary', [DashboardController::class, 'summary'])
        ->middleware('can:view-dashboard');

    Route::get('/tickets/meta', [TicketController::class, 'meta']);
    Route::post('/tickets/{ticket}/comments', [TicketController::class, 'storeComment']);
    Route::apiResource('tickets', TicketController::class);

    Route::prefix('webmaster')->group(function () {
        Route::get('/tasks/meta', [WebmasterTaskController::class, 'meta']);
        Route::apiResource('tasks', WebmasterTaskController::class);
    });

    Route::apiResource('users', UserController::class);
});
