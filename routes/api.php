<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CommentaryController;
use App\Http\Controllers\Api\GameController;
use App\Http\Controllers\Api\InsightsController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Middleware\EnsureAdmin;
use Illuminate\Support\Facades\Route;

// Public auth routes
Route::post('/register',         [AuthController::class,        'register']);
Route::post('/login',            [AuthController::class,        'login']);
Route::post('/forgot-password',  [PasswordResetController::class, 'forgotPassword']);
Route::post('/reset-password',   [PasswordResetController::class, 'resetPassword']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me',               [AuthController::class, 'me']);
    Route::post('/logout',          [AuthController::class, 'logout']);
    Route::post('/me/username',     [AuthController::class, 'updateUsername']);

    Route::post('/sync', [SyncController::class, 'sync']);

    Route::get('/games',                   [GameController::class, 'index']);
    Route::get('/games/{game}',            [GameController::class, 'show']);
    Route::post('/games/{game}/analyze',   [GameController::class, 'saveAnalysis']);

    Route::get('/insights', [InsightsController::class, 'index']);

    Route::post('/chess/commentary', [CommentaryController::class, 'generate']);

    // Admin-only routes
    Route::middleware(EnsureAdmin::class)->prefix('admin')->group(function () {
        Route::get('/dashboard',            [AdminController::class, 'dashboard']);
        Route::get('/users',                [AdminController::class, 'users']);
        Route::post('/users/{user}/toggle-admin', [AdminController::class, 'toggleAdmin']);
        Route::delete('/users/{user}',      [AdminController::class, 'deleteUser']);
    });
});
