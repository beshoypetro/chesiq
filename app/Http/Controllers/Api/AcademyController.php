<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ContentDraft;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Module;
use App\Models\ModuleProgress;
use App\Models\QuizAttempt;
use App\Models\Track;
use App\Services\ContentGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        $progress = \DB::table('module_progress')
            ->where('user_id', $request->user()->id)
            ->where('module_id', $id)
            ->first();

        return response()->json([
            'module' => $module,
            'progress' => $progress,
        ]);
    }

    public function enroll(Request $request, int $courseId): JsonResponse
    {
        $course = Course::findOrFail($courseId);
        \DB::table('course_enrollments')->updateOrInsert(
            ['user_id' => $request->user()->id, 'course_id' => $course->id],
            ['enrolled_at' => now(), 'updated_at' => now(), 'created_at' => now()],
        );
        return response()->json(['enrolled' => true]);
    }

    public function completeActivity(Request $request, int $activityId): JsonResponse
    {
        $activity = Activity::findOrFail($activityId);
        $module = $activity->module;
        $totalActivities = $module->activities()->where('published', true)->count();

        \DB::table('module_progress')->updateOrInsert(
            ['user_id' => $request->user()->id, 'module_id' => $module->id],
            [
                'started_at' => now(),
                'activities_total' => $totalActivities,
                'activities_completed' => \DB::raw("COALESCE((SELECT activities_completed FROM module_progress WHERE user_id = {$request->user()->id} AND module_id = {$module->id}), 0) + 1"),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        return response()->json(['ok' => true]);
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
