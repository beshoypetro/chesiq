<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Lesson;
use App\Models\PatternReviewSchedule;
use App\Models\Puzzle;
use App\Models\User;
use App\Models\UserFailurePattern;
use Illuminate\Support\Carbon;

/**
 * Daily homework set — SM-2 spaced repetition extended to failure patterns.
 * Extracted from HomeworkController::today (V3 P2) so the coach session
 * script (CoachSessionService) can compose the same data without an HTTP
 * round-trip. The array shape is the controller's response shape — keep it
 * byte-identical.
 */
class HomeworkService
{
    /**
     * @return array{pattern_reviews: \Illuminate\Support\Collection, puzzles: \Illuminate\Support\Collection, lesson_review: ?array, archive_game: ?Game}
     */
    public function today(User $user): array
    {
        $duePatterns = PatternReviewSchedule::where('user_id', $user->id)
            ->whereDate('due_at', '<=', Carbon::today())
            ->with('failurePattern')
            ->orderBy('due_at')
            ->limit(3)
            ->get();

        // If user has zero schedule rows yet, seed from existing failure patterns.
        if ($duePatterns->isEmpty()) {
            $patterns = UserFailurePattern::where('user_id', $user->id)
                ->orderByDesc('occurrence_count')
                ->limit(3)
                ->get();
            foreach ($patterns as $p) {
                PatternReviewSchedule::firstOrCreate(
                    ['user_id' => $user->id, 'failure_pattern_id' => $p->id],
                    ['due_at' => Carbon::today()],
                );
            }
            $duePatterns = PatternReviewSchedule::where('user_id', $user->id)
                ->whereDate('due_at', '<=', Carbon::today())
                ->with('failurePattern')
                ->limit(3)
                ->get();
        }

        $puzzles = Puzzle::query()
            ->inRandomOrder()
            ->limit(10)
            ->get(['id', 'fen', 'moves', 'rating', 'themes']);

        $archiveGame = Game::where('user_id', $user->id)
            ->whereNotNull('analyzed_at')
            ->orderByDesc('played_at')
            ->skip(7)
            ->first(['id', 'opening_name', 'eco_code', 'result', 'played_at']);

        return [
            'pattern_reviews' => $duePatterns,
            'puzzles' => $puzzles,
            'lesson_review' => $this->resolveLessonReview($user, $duePatterns),
            'archive_game' => $archiveGame,
        ];
    }

    /**
     * Pick one lesson worth re-studying today: first matching the user's most
     * frequent failure pattern by theme, then anything at their level, then a
     * sensible default. Returns null only when no lessons exist at all.
     *
     * @param  \Illuminate\Support\Collection<int, PatternReviewSchedule>  $duePatterns
     */
    private function resolveLessonReview(User $user, $duePatterns): ?array
    {
        $levelMap = [
            'foundations' => 'beginner',
            'improver' => 'intermediate',
            'club' => 'intermediate',
            'tournament' => 'advanced',
        ];
        $level = $levelMap[$user->placement_track] ?? null;

        $pattern = optional($duePatterns->first())->failurePattern;
        $hint = $pattern->pattern_kind ?? $pattern->phase ?? null;

        $lesson = null;
        if ($hint) {
            $lesson = Lesson::where('theme', 'like', '%'.$hint.'%')->first();
        }
        if (! $lesson && $level) {
            $lesson = Lesson::where('level', $level)->inRandomOrder()->first();
        }
        if (! $lesson) {
            $lesson = Lesson::inRandomOrder()->first();
        }
        if (! $lesson) {
            return null;
        }

        return [
            'id' => $lesson->id,
            'title' => $lesson->title,
            'theme' => $lesson->theme,
            'level' => $lesson->level,
            'video_url' => $lesson->video_url,
            'reason' => $hint
                ? 'Reinforces your recurring "'.str_replace('_', ' ', $hint).'" pattern.'
                : 'A timely refresher for your level.',
        ];
    }
}
