<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LessonController extends Controller
{
    /**
     * GET /api/lessons — list all lessons with user progress.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $theme = $request->query('theme');
        $level = $request->query('level');

        $query = Lesson::query();
        if ($theme) {
            $query->where('theme', $theme);
        }
        if ($level) {
            $query->where('level', $level);
        }

        $lessons = $query->get();

        $completed = DB::table('user_lesson_progress')
            ->where('user_id', $user->id)
            ->pluck('completed_at', 'lesson_id');

        $result = $lessons->map(fn ($l) => [
            'id'            => $l->id,
            'title'         => $l->title,
            'video_url'     => $l->video_url,
            'theme'         => $l->theme,
            'level'         => $l->level,
            'puzzle_set_id' => $l->puzzle_set_id,
            'completed'     => isset($completed[$l->id]),
            'completed_at'  => $completed[$l->id] ?? null,
        ]);

        return response()->json(['lessons' => $result]);
    }

    /**
     * GET /api/lessons/{id} — single lesson detail.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $lesson = Lesson::findOrFail($id);

        $progress = DB::table('user_lesson_progress')
            ->where('user_id', $user->id)
            ->where('lesson_id', $id)
            ->first();

        return response()->json([
            'lesson'    => array_merge($lesson->toArray(), [
                'completed'    => (bool) $progress,
                'completed_at' => $progress?->completed_at,
            ]),
        ]);
    }

    /**
     * POST /api/lessons/{id}/complete — mark a lesson as watched.
     */
    public function complete(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        Lesson::findOrFail($id); // 404 if not found

        DB::table('user_lesson_progress')->updateOrInsert(
            ['user_id' => $user->id, 'lesson_id' => $id],
            ['completed_at' => now()]
        );

        return response()->json(['message' => 'Lesson marked as completed.']);
    }
}
