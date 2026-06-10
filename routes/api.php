<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AchievementController;
use App\Http\Controllers\Api\AnnotationController;
use App\Http\Controllers\Api\AcademyController;
use App\Http\Controllers\Api\AssessmentController;
use App\Http\Controllers\Api\ChessDnaController;
use App\Http\Controllers\Api\DailyReviewController;
use App\Http\Controllers\Api\HomeworkController;
use App\Http\Controllers\Api\ImprovementPlanController;
use App\Http\Controllers\Api\TeacherController;
use App\Http\Controllers\Api\TelemetryController;
use App\Http\Controllers\Api\TrainerCharacterController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\CommentaryController;
use App\Http\Controllers\Api\DatabaseController;
use App\Http\Controllers\Api\DigestController;
use App\Http\Controllers\Api\DrillController;
use App\Http\Controllers\Api\DrillQueueController;
use App\Http\Controllers\Api\EndgameController;
use App\Http\Controllers\Api\ExplorerController;
use App\Http\Controllers\Api\GameController;
use App\Http\Controllers\Api\HintController;
use App\Http\Controllers\Api\InsightsController;
use App\Http\Controllers\Api\LearnController;
use App\Http\Controllers\Api\LessonController;
use App\Http\Controllers\Api\ModelGamesController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PuzzleController;
use App\Http\Controllers\Api\PuzzleSetController;
use App\Http\Controllers\Api\RankingController;
use App\Http\Controllers\Api\RepertoireController;
use App\Http\Controllers\Api\StudyPlanController;
use App\Http\Controllers\Api\StyleController;
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

// Public digest unsubscribe (no auth required — token in URL)
Route::get('/digest/unsubscribe', [DigestController::class, 'unsubscribe']);

// V2 trainer registry — public so the selection page can render without auth.
Route::get('/trainers', [TrainerCharacterController::class, 'index']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    // Throttled because updateUsername probes chess.com's public API,
    // which lets an attacker enumerate chess.com usernames at authed-user speed otherwise.
    Route::middleware('throttle:10,1')->post('/me/username', [AuthController::class, 'updateUsername']);

    // V2 user prefs + onboarding (spec §22)
    Route::patch('/user/trainer', [TrainerCharacterController::class, 'select']);
    Route::patch('/user/intent', [AuthController::class, 'updateIntent']);
    Route::patch('/user/coaching-mode', [AuthController::class, 'updateCoachingMode']);
    Route::patch('/user/preferences', [AuthController::class, 'updatePreferences']);
    Route::get('/user/onboarding', [AuthController::class, 'onboardingStatus']);
    Route::post('/user/onboarding/skip', [AuthController::class, 'onboardingSkip']);

    // V2 Phase H — per-user weekly plan
    Route::get('/plan/current', [PlanController::class, 'current']);
    // LLM-backed — gate behind the same throttle as other Gemini calls.
    Route::middleware('throttle:30,1')->post('/plan/generate', [PlanController::class, 'generate']);

    // Improvement Plan — durable adaptive long-horizon plan (IMPROVEMENT_PLAN_SPEC §9)
    Route::get('/improvement-plan', [ImprovementPlanController::class, 'show']);
    Route::middleware('throttle:30,1')->post('/improvement-plan/sync', [ImprovementPlanController::class, 'sync']);
    Route::post('/improvement-plan/action/complete', [ImprovementPlanController::class, 'completeAction']);

    // V2 Phase K — client telemetry events. Throttled high; payloads are tiny.
    Route::middleware('throttle:120,1')->post('/telemetry/event', [TelemetryController::class, 'event']);

    Route::post('/sync', [SyncController::class, 'sync']);

    Route::get('/games', [GameController::class, 'index']);
    Route::get('/games/{game}', [GameController::class, 'show']);
    Route::post('/games/{game}/analyze', [GameController::class, 'saveAnalysis']);

    Route::get('/insights', [InsightsController::class, 'index']);

    // LLM endpoints — throttled per-user to protect free-tier quotas from abuse/runaway loops
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('/chess/commentary', [CommentaryController::class, 'generate']);
        Route::post('/training/coach', [TrainerController::class,    'coach']);
        // F005: In-game hint
        Route::post('/chess/hint', [HintController::class, 'hint']);
        // T3.9: conversational coach
        Route::post('/chess/chat', [ChatController::class, 'chat']);
        // T2.8: end-of-game coach summary
        Route::post('/games/{game}/coach-summary', [GameController::class, 'coachSummary']);
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

    // Game annotations (F036)
    Route::get('/games/{game}/annotations', [AnnotationController::class, 'index']);
    Route::post('/games/{game}/annotations', [AnnotationController::class, 'store']);
    Route::delete('/games/{game}/annotations/{moveIndex}', [AnnotationController::class, 'destroy']);

    // Game phase accuracy (F033)
    Route::get('/insights/phase-accuracy', [InsightsController::class, 'phaseAccuracy']);

    // Puzzle theme weakness (F011)
    Route::get('/insights/puzzle-themes', [InsightsController::class, 'puzzleThemes']);

    // Playing style (F031)
    Route::get('/insights/style', [StyleController::class, 'index']);

    // Position Drill Mode (F029)
    Route::get('/drills', [DrillController::class, 'index']);
    Route::post('/drills', [DrillController::class, 'store']);
    Route::post('/drills/{id}/attempt', [DrillController::class, 'attempt']);
    Route::delete('/drills/{id}', [DrillController::class, 'destroy']);

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

    // Endgame trainer (F007)
    Route::prefix('endgame')->group(function () {
        Route::get('/next', [EndgameController::class, 'next']);
        Route::post('/{id}/attempt', [EndgameController::class, 'attempt']);
        Route::get('/tablebase', [EndgameController::class, 'tablebase']);
        Route::get('/ratings', [EndgameController::class, 'ratings']);
    });

    // Achievements (F008)
    Route::get('/achievements', [AchievementController::class, 'index']);
    Route::get('/achievements/user', [AchievementController::class, 'user']);

    // Study plan (F022)
    Route::get('/study-plan', [StudyPlanController::class, 'index']);
    Route::post('/study-plan/override', [StudyPlanController::class, 'override']);

    // Opening explorer (F003)
    Route::get('/chess/explorer', [ExplorerController::class, 'index']);

    // Master game database (F021)
    Route::get('/database/games', [DatabaseController::class, 'games']);

    // Opening model games (F023)
    Route::get('/learn/lines/{lineId}/model-games', [ModelGamesController::class, 'index']);

    // Video lessons (F025)
    Route::get('/lessons', [LessonController::class, 'index']);
    Route::get('/lessons/{id}', [LessonController::class, 'show']);
    Route::post('/lessons/{id}/complete', [LessonController::class, 'complete']);

    // Placement Assessment (academy onboarding)
    Route::prefix('assessment')->group(function () {
        Route::get('/status', [AssessmentController::class, 'status']);
        Route::post('/start', [AssessmentController::class, 'start']);
        Route::post('/answer', [AssessmentController::class, 'answer']);
        Route::get('/result/{id}', [AssessmentController::class, 'result']);
        // V2 Phase G — skip path that infers placement from game history.
        Route::post('/estimate-from-games', [AssessmentController::class, 'estimateFromGames']);
    });

    // Ranking / rating-progress timeline (no new table — derived from games)
    Route::get('/ranking', [RankingController::class, 'index']);

    // Daily Review Hub
    Route::get('/daily-review', [DailyReviewController::class, 'index']);

    // Daily homework — spaced repetition over patterns + puzzles
    Route::get('/homework/today', [HomeworkController::class, 'today']);
    Route::post('/homework/pattern/{scheduleId}/review', [HomeworkController::class, 'reviewPattern']);

    // Adaptive Academy
    Route::prefix('academy')->group(function () {
        Route::get('/tracks', [AcademyController::class, 'tracks']);
        Route::get('/tracks/{slug}', [AcademyController::class, 'track']);
        Route::get('/modules/{id}', [AcademyController::class, 'module']);
        Route::get('/activities/{id}', [AcademyController::class, 'activity']);
        Route::post('/courses/{courseId}/enroll', [AcademyController::class, 'enroll']);
        Route::post('/activities/{activityId}/complete', [AcademyController::class, 'completeActivity']);
    });

    // AI Teacher (conversational coach)
    Route::middleware('throttle:60,1')->prefix('teacher')->group(function () {
        Route::get('/conversations', [TeacherController::class, 'listConversations']);
        Route::post('/start', [TeacherController::class, 'start']);
        Route::get('/conversations/{id}', [TeacherController::class, 'show']);
        Route::post('/message', [TeacherController::class, 'message']);
        Route::post('/transcribe', [TeacherController::class, 'transcribe']);
    });

    // Chess DNA Profile (academy)
    Route::get('/profile/chess-dna', [ChessDnaController::class, 'dna']);
    Route::get('/profile/openings/{eco}', [ChessDnaController::class, 'opening']);
    Route::get('/profile/patterns', function (\Illuminate\Http\Request $r) {
        return response()->json([
            'patterns' => \App\Models\UserFailurePattern::where('user_id', $r->user()->id)
                ->orderByDesc('occurrence_count')->limit(50)->get(),
        ]);
    });

    // Admin-only routes
    Route::middleware(EnsureAdmin::class)->prefix('admin')->group(function () {
        // Academy authoring
        Route::post('/academy/drafts', [AcademyController::class, 'generateDraft']);
        Route::get('/academy/drafts', [AcademyController::class, 'listDrafts']);
        Route::post('/academy/drafts/{id}/approve', [AcademyController::class, 'approveDraft']);

        // Telemetry dashboard
        Route::get('/telemetry', [TelemetryController::class, 'dashboard']);

        Route::get('/dashboard', [AdminController::class, 'dashboard']);
        Route::get('/users', [AdminController::class, 'users']);
        Route::post('/users/{user}/toggle-admin', [AdminController::class, 'toggleAdmin']);
        Route::delete('/users/{user}', [AdminController::class, 'deleteUser']);
        // F030: Email digest admin preview
        Route::get('/digest/preview/{userId}', [DigestController::class, 'preview']);
        // F023: Admin add model game
        Route::post('/learn/lines/{lineId}/model-games', [ModelGamesController::class, 'store']);
    });
});
