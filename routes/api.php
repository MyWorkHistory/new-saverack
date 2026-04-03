<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientAccountController;
use App\Http\Controllers\Api\ClientStoreController;
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
        Route::post('/tasks/{task}/comments', [WebmasterTaskController::class, 'storeComment']);
        Route::get('/tasks/{task}/comments/{comment}/attachment', [WebmasterTaskController::class, 'downloadCommentAttachment']);
        Route::apiResource('tasks', WebmasterTaskController::class);
    });

    Route::post('users/{user}/avatar', [UserController::class, 'uploadAvatar'])
        ->name('users.avatar.store');
    Route::delete('users/{user}/avatar', [UserController::class, 'destroyAvatar'])
        ->name('users.avatar.destroy');
    Route::patch('users/bulk', [UserController::class, 'bulkUpdate'])
        ->name('users.bulk-update');

    Route::get('client-accounts/meta', [ClientAccountController::class, 'meta'])
        ->name('client-accounts.meta');
    Route::patch('client-accounts/bulk', [ClientAccountController::class, 'bulkUpdate'])
        ->name('client-accounts.bulk-update');
    Route::get('client-accounts/{client_account}/stores', [ClientStoreController::class, 'index'])
        ->name('client-accounts.stores.index');
    Route::post('client-accounts/{client_account}/stores', [ClientStoreController::class, 'store'])
        ->name('client-accounts.stores.store');
    Route::patch('client-stores/{client_store}', [ClientStoreController::class, 'update'])
        ->name('client-stores.update');
    Route::delete('client-stores/{client_store}', [ClientStoreController::class, 'destroy'])
        ->name('client-stores.destroy');
    Route::apiResource('client-accounts', ClientAccountController::class);
    Route::match(['put', 'patch'], 'users/{user}/permissions', [UserController::class, 'updatePermissions'])
        ->name('users.permissions.update');
    Route::get('users/{user}/history', [UserController::class, 'history'])
        ->name('users.history');
    Route::apiResource('users', UserController::class);
});
