<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CommentaryController;
use App\Http\Controllers\Api\DrillQueueController;
use App\Http\Controllers\Api\GameController;
use App\Http\Controllers\Api\InsightsController;
use App\Http\Controllers\Api\LearnController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PuzzleController;
use App\Http\Controllers\Api\PuzzleSetController;
use App\Http\Controllers\Api\RepertoireController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\TrainerController;
use App\Http\Controllers\Api\TrainingController;
use App\Http\Controllers\Api\TtsController;
use App\Http\Middleware\EnsureAdmin;
use Illuminate\Support\Facades\Route;

// Public auth routes (throttled to resist credential-stuffing and reset-spam)
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/register', [AuthController::class,        'register']);
    Route::post('/login', [AuthController::class,        'login']);
    Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword']);
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);
});

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    // Throttled because updateUsername probes chess.com's public API,
    // which lets an attacker enumerate chess.com usernames at authed-user speed otherwise.
    Route::middleware('throttle:10,1')->post('/me/username', [AuthController::class, 'updateUsername']);

    Route::post('/sync', [SyncController::class, 'sync']);

    Route::get('/games', [GameController::class, 'index']);
    Route::get('/games/{game}', [GameController::class, 'show']);
    Route::post('/games/{game}/analyze', [GameController::class, 'saveAnalysis']);

    Route::get('/insights', [InsightsController::class, 'index']);

    // LLM endpoints — throttled per-user to protect free-tier quotas from abuse/runaway loops
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('/chess/commentary', [CommentaryController::class, 'generate']);
        Route::post('/training/coach', [TrainerController::class,    'coach']);
    });

    // Local TTS (Piper). Heavier throttle because voice fires per move.
    Route::middleware('throttle:120,1')->group(function () {
        Route::post('/tts', [TtsController::class, 'synthesize']);
    });

    // Trainer (opening drill with voice coach)
    Route::prefix('training')->group(function () {
        Route::get('/plan', [TrainerController::class, 'plan']);
        Route::get('/line/{lineId}', [TrainerController::class, 'line']);
        Route::post('/attempt', [TrainerController::class, 'attempt']);
        Route::post('/line/{lineId}/complete', [TrainerController::class, 'completeLine']);
        Route::post('/session/start', [TrainerController::class, 'startSession']);
        Route::post('/session/{id}/end', [TrainerController::class, 'endSession']);
    });

    // Puzzles (F001 + F024 streak + F002 rush)
    Route::prefix('puzzles')->group(function () {
        Route::get('/next', [PuzzleController::class, 'next']);
        Route::post('/{puzzleId}/attempt', [PuzzleController::class, 'attempt']);
        Route::get('/themes', [PuzzleController::class, 'themes']);
        Route::get('/history', [PuzzleController::class, 'history']);
        // F024 Streak
        Route::post('/streak/score', [PuzzleController::class, 'streakScore']);
        Route::get('/streak/best', [PuzzleController::class, 'streakBest']);
        // F002 Rush
        Route::post('/rush/score', [PuzzleController::class, 'rushScore']);
        Route::get('/rush/best', [PuzzleController::class, 'rushBest']);
    });

    // Game phase accuracy (F033)
    Route::get('/insights/phase-accuracy', [InsightsController::class, 'phaseAccuracy']);

    // Puzzle theme weakness (F011)
    Route::get('/insights/puzzle-themes', [InsightsController::class, 'puzzleThemes']);

    // Puzzle Sets (F019)
    Route::get('/puzzle-sets', [PuzzleSetController::class, 'index']);
    Route::post('/puzzle-sets', [PuzzleSetController::class, 'store']);
    Route::get('/puzzle-sets/{id}/next', [PuzzleSetController::class, 'next']);
    Route::post('/puzzle-sets/{id}/puzzles/{puzzleId}', [PuzzleSetController::class, 'addPuzzle']);

    // Mistake puzzle from game (F010)
    Route::get('/games/{game}/puzzle/{moveIndex}', [GameController::class, 'mistakePuzzle']);

    // Drill queue (F032)
    Route::post('/drill-queue', [DrillQueueController::class, 'store']);
    Route::get('/drill-queue/next', [DrillQueueController::class, 'next']);
    Route::post('/drill-queue/{id}/solve', [DrillQueueController::class, 'solve']);

    // Spaced repetition for openings (F006)
    Route::get('/learn/due', [LearnController::class, 'due']);
    Route::post('/learn/{moveId}/review', [LearnController::class, 'review']);
    Route::post('/learn/track', [LearnController::class, 'track']);

    // Coordinate training (F013)
    Route::post('/training/coordinates/score', [TrainingController::class, 'coordinateScore']);
    Route::get('/training/coordinates/best', [TrainingController::class, 'coordinateBest']);

    // Vision drills (F035)
    Route::post('/training/vision/score', [TrainingController::class, 'visionScore']);
    Route::get('/training/vision/best', [TrainingController::class, 'visionBest']);

    // Play vs AI / save bot game (F004 + F037)
    Route::post('/games', [GameController::class, 'store']);
    Route::get('/training/ai-level', [GameController::class, 'aiLevel']);
    Route::post('/training/ai-level/update', [GameController::class, 'updateAiLevel']);

    // Repertoires (F018 + F027 coverage)
    Route::prefix('repertoires')->group(function () {
        Route::get('/', [RepertoireController::class, 'index']);
        Route::post('/', [RepertoireController::class, 'store']);
        Route::get('/{id}', [RepertoireController::class, 'show']);
        Route::patch('/{id}', [RepertoireController::class, 'update']);
        Route::delete('/{id}', [RepertoireController::class, 'destroy']);
        Route::get('/{id}/export.pgn', [RepertoireController::class, 'exportPgn']);
        Route::get('/{id}/coverage', [RepertoireController::class, 'coverage']); // F027
    });

    // Admin-only routes
    Route::middleware(EnsureAdmin::class)->prefix('admin')->group(function () {
        Route::get('/dashboard', [AdminController::class, 'dashboard']);
        Route::get('/users', [AdminController::class, 'users']);
        Route::post('/users/{user}/toggle-admin', [AdminController::class, 'toggleAdmin']);
        Route::delete('/users/{user}', [AdminController::class, 'deleteUser']);
    });
});
