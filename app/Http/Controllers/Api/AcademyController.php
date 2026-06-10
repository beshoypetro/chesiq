<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ContentDraft;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Module;
use App\Models\ModuleCertificate;
use App\Models\ModuleProgress;
use App\Models\Puzzle;
use App\Models\QuizAttempt;
use App\Models\Track;
use App\Services\ContentGenerationService;
use App\Services\ImprovementPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AcademyController extends Controller
{
    public function tracks(): JsonResponse
    {
        $tracks = Track::where('published', true)
            ->orderBy('display_order')
            ->with(['courses' => fn ($q) => $q->withCount('modules')])
            ->get();
        return response()->json(['tracks' => $tracks]);
    }

    public function track(string $slug): JsonResponse
    {
        $track = Track::where('slug', $slug)
            ->with(['courses.modules' => fn ($q) => $q->where('published', true)])
            ->firstOrFail();
        return response()->json(['track' => $track]);
    }

    public function module(Request $request, int $id): JsonResponse
    {
        $module = Module::with(['activities' => fn ($q) => $q->where('published', true)])->findOrFail($id);
        $progress = ModuleProgress::where('user_id', $request->user()->id)
            ->where('module_id', $id)
            ->first();

        return response()->json([
            'module' => $module,
            'progress' => $progress,
        ]);
    }

    /**
     * Activity content resolution — the runner data. Returns the activity plus
     * the resolved content the frontend needs to actually run it, the parent
     * module name (breadcrumb), and the user's progress ("activity N of M").
     */
    public function activity(Request $request, int $id): JsonResponse
    {
        $activity = Activity::with('module')->findOrFail($id);
        $module = $activity->module;
        $config = $activity->config ?? [];

        $content = $this->resolveActivityContent($activity, $config, $module);

        $progress = ModuleProgress::where('user_id', $request->user()->id)
            ->where('module_id', $module->id)
            ->first();

        return response()->json([
            'activity' => [
                'id' => $activity->id,
                'type' => $activity->type,
                'title' => $activity->title,
                'config' => $config,
                'module_id' => $module->id,
                'module_name' => $module->name,
            ],
            'content' => $content,
            'progress' => $progress,
        ]);
    }

    /**
     * Per-type resolution so the frontend can launch the right runner.
     * Always returns at least the data named in the build spec; unknown types
     * fall through with the raw config so nothing 500s.
     */
    private function resolveActivityContent(Activity $activity, array $config, Module $module): array
    {
        return match ($activity->type) {
            'lesson_markdown' => [
                'markdown' => $config['markdown'] ?? '',
                // Optional trainer-narrated beats; when present the frontend
                // delivers a spoken, board-forward lesson instead of markdown.
                'beats' => $config['beats'] ?? null,
            ],
            'guided_study' => [
                'markdown' => $config['markdown'] ?? '',
                'beats' => $config['beats'] ?? null,
                'master_game_id' => $config['master_game_id'] ?? null,
            ],
            'quiz' => [
                // The user's own quiz — frontend needs prompt/options/correct_index/explanation.
                'questions' => $config['questions'] ?? [],
            ],
            'puzzle_set' => $this->resolvePuzzleSet($config, $module),
            'endgame_set' => [
                'category' => $config['category'] ?? null,
                'count' => (int) ($config['count'] ?? 10),
            ],
            // repertoire_lines / video_lesson / position_drill / calculation_drill
            // carry their config straight through — the frontend launches the
            // matching existing trainer with these params.
            default => $config,
        };
    }

    /**
     * Resolve a puzzle_set activity to real puzzle IDs. Explicit puzzle_ids win;
     * otherwise query the puzzles table by theme within the track's Elo band.
     * Returns puzzle_ids (always) + the rows the runner needs.
     */
    private function resolvePuzzleSet(array $config, Module $module): array
    {
        $count = (int) ($config['count'] ?? 10);

        if (! empty($config['puzzle_ids']) && is_array($config['puzzle_ids'])) {
            $ids = array_values($config['puzzle_ids']);
        } else {
            $theme = $config['theme'] ?? null;
            [$eloMin, $eloMax] = $this->trackEloBand($module, $config['elo_band'] ?? null);

            $query = Puzzle::query();
            if ($theme) {
                $query->where('themes', 'like', "%{$theme}%");
            }
            $query->whereBetween('rating', [$eloMin, $eloMax]);

            $ids = $query->limit($count)->pluck('id')->all();

            // Widen if the band yielded nothing (sparse dev datasets).
            if (empty($ids)) {
                $fallback = Puzzle::query();
                if ($theme) {
                    $fallback->where('themes', 'like', "%{$theme}%");
                }
                $ids = $fallback->limit($count)->pluck('id')->all();
            }
        }

        $puzzles = Puzzle::whereIn('id', $ids)->get()->map(fn ($p) => [
            'id' => $p->id,
            'fen' => $p->fen,
            'moves' => $p->moves ? explode(' ', $p->moves) : [],
            'rating' => $p->rating,
            'themes' => $p->themes ? explode(' ', $p->themes) : [],
        ])->all();

        return [
            'theme' => $config['theme'] ?? null,
            'count' => $count,
            'puzzle_ids' => $ids,
            'puzzles' => $puzzles,
        ];
    }

    /**
     * Elo band for a puzzle_set: prefer the activity's own elo_band slug, else
     * the owning track's [elo_min, elo_max]. Defaults wide if neither resolves.
     */
    private function trackEloBand(Module $module, ?string $eloBandSlug): array
    {
        $bands = [
            'foundations' => [400, 999],
            'improver' => [1000, 1499],
            'club' => [1500, 1899],
            'tournament' => [1900, 2600],
        ];

        if ($eloBandSlug && isset($bands[$eloBandSlug])) {
            return $bands[$eloBandSlug];
        }

        $track = optional(optional($module->course)->track);
        if ($track && $track->elo_min !== null && $track->elo_max !== null) {
            return [(int) $track->elo_min, (int) $track->elo_max];
        }

        return [400, 2600];
    }

    public function enroll(Request $request, int $courseId): JsonResponse
    {
        $course = Course::findOrFail($courseId);
        $this->enrollUserInCourse($request->user()->id, $course);

        return response()->json(['enrolled' => true]);
    }

    public function completeActivity(Request $request, int $activityId): JsonResponse
    {
        $data = $request->validate([
            'score' => 'nullable|numeric',
            'passed' => 'nullable|boolean',
        ]);

        $activity = Activity::findOrFail($activityId);
        $module = $activity->module;
        $userId = $request->user()->id;
        $totalActivities = $module->activities()->where('published', true)->count();

        // Find-or-create the progress row, initializing the total from the
        // module's published activity count.
        $progress = ModuleProgress::firstOrNew([
            'user_id' => $userId,
            'module_id' => $module->id,
        ]);
        if (! $progress->exists) {
            $progress->started_at = now();
            $progress->completed_activity_ids_json = [];
        }
        $progress->activities_total = $totalActivities;

        // Idempotent: track the distinct set of completed activity ids so
        // re-completing the same activity never double-counts.
        $completedIds = $progress->completed_activity_ids_json ?? [];
        if (! in_array($activity->id, $completedIds, true)) {
            $completedIds[] = $activity->id;
        }
        $progress->completed_activity_ids_json = array_values($completedIds);
        $progress->activities_completed = count($completedIds);

        $moduleCompleted = false;
        $certificate = null;

        // When every published activity is done, complete the module and issue
        // a certificate (idempotent — one per user+module).
        if ($totalActivities > 0 && $progress->activities_completed >= $totalActivities) {
            if (! $progress->completed_at) {
                $progress->completed_at = now();
            }
            $moduleCompleted = true;

            $cert = ModuleCertificate::firstOrCreate(
                ['user_id' => $userId, 'module_id' => $module->id],
                ['serial' => $this->generateSerial(), 'issued_at' => now()],
            );
            $certificate = ['serial' => $cert->serial];
        }

        $progress->save();

        // Academy activity completed → bump the activities criterion + advance
        // the next action (spec §7). Defensive: never break the completion call.
        try {
            app(ImprovementPlanService::class)->sync($request->user());
        } catch (\Throwable $e) {
            Log::warning('ImprovementPlan sync (academy activity) failed', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'ok' => true,
            'module_completed' => $moduleCompleted,
            'certificate' => $certificate,
        ]);
    }

    /** A short, unique-per-issue certificate serial (uppercase, no ambiguity). */
    private function generateSerial(): string
    {
        return 'CERT-'.strtoupper(Str::random(12));
    }

    /**
     * Auto-enroll a user into the recommended track's FIRST course after
     * placement is finalized. Resolves the track by its `placement_track` slug,
     * picks the first published course by display_order, enrolls (no dup), and
     * seeds the first module's progress row. Callable from AssessmentController.
     */
    public function autoEnrollFromPlacement(int $userId, ?string $trackSlug): void
    {
        if (! $trackSlug) {
            return;
        }

        $track = Track::where('slug', $trackSlug)->first();
        if (! $track) {
            return;
        }

        $course = $track->courses()->orderBy('display_order')->first();
        if (! $course) {
            return;
        }

        $this->enrollUserInCourse($userId, $course);
    }

    /**
     * Enroll a user into a course (idempotent) and seed a module_progress row
     * for that course's first module. Shared by the enroll endpoint and the
     * placement auto-enroll path. Never throws on the progress seed.
     */
    private function enrollUserInCourse(int $userId, Course $course): void
    {
        CourseEnrollment::updateOrCreate(
            ['user_id' => $userId, 'course_id' => $course->id],
            ['enrolled_at' => now()],
        );

        $firstModule = $course->modules()
            ->where('published', true)
            ->orderBy('display_order')
            ->first();

        if (! $firstModule) {
            return;
        }

        $total = $firstModule->activities()->where('published', true)->count();

        ModuleProgress::firstOrCreate(
            ['user_id' => $userId, 'module_id' => $firstModule->id],
            [
                'started_at' => now(),
                'activities_total' => $total,
                'activities_completed' => 0,
                'completed_activity_ids_json' => [],
            ],
        );
    }

    public function generateDraft(Request $request, ContentGenerationService $svc): JsonResponse
    {
        $data = $request->validate([
            'kind' => 'required|in:lesson,quiz,puzzle_set,guided_study',
            'topic' => 'required|string|max:200',
            'elo_band' => 'required|in:foundations,improver,club,tournament',
            'extra' => 'nullable|array',
        ]);

        $draft = match ($data['kind']) {
            'lesson' => $svc->draftLesson($data['topic'], $data['elo_band']),
            'quiz' => $svc->draftQuiz($data['topic'], $data['elo_band'], (int) ($data['extra']['count'] ?? 5)),
            'puzzle_set' => $svc->selectPuzzleSet($data['topic'], $data['elo_band'], (int) ($data['extra']['count'] ?? 20)),
            'guided_study' => $svc->draftGuidedStudy((int) ($data['extra']['master_game_id'] ?? 0), $data['elo_band']),
        };

        return response()->json(['draft' => $draft]);
    }

    public function listDrafts(Request $request): JsonResponse
    {
        $drafts = ContentDraft::orderByDesc('created_at')->limit(50)->get();
        return response()->json(['drafts' => $drafts]);
    }

    public function approveDraft(Request $request, int $id): JsonResponse
    {
        $draft = ContentDraft::findOrFail($id);
        $draft->update([
            'status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);
        return response()->json(['draft' => $draft]);
    }
}
