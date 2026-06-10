<?php

namespace App\Services;

use App\Models\ImprovementPlan;
use App\Models\MoveAnalysis;
use App\Models\User;
use App\Models\UserFailurePattern;

/**
 * Composes the coach-led daily session script (CHESSIQ_V3_PLAN §2): an
 * ordered list of voiced steps the frontend SessionRunner walks through.
 * Deterministic and $0 — templated `say` lines interpolated with real user
 * data; no LLM call. The Gemini-grade back-and-forth happens through the
 * "Ask why" escape hatch (the Teacher) when the student wants depth.
 *
 * Step shape: { type: greeting|quiz|puzzle|pattern_review|wrapup, say, ...payload }.
 */
class CoachSessionService
{
    public function __construct(private HomeworkService $homework) {}

    /**
     * @return array{date: string, steps: list<array<string, mixed>>}
     */
    public function buildToday(User $user): array
    {
        $steps = [];
        $homework = $this->homework->today($user);

        $steps[] = $this->greetingStep($user);

        $quiz = $this->quizStep($user);
        if ($quiz !== null) {
            $steps[] = $quiz;
        }

        foreach ($this->puzzleSteps($homework, $quiz !== null ? 2 : 3) as $step) {
            $steps[] = $step;
        }

        $patternReview = $this->patternReviewStep($homework);
        if ($patternReview !== null) {
            $steps[] = $patternReview;
        }

        $steps[] = $this->wrapupStep($user);

        return [
            'date' => now()->toDateString(),
            'steps' => $steps,
        ];
    }

    private function greetingStep(User $user): array
    {
        $name = trim(explode(' ', (string) $user->name)[0] ?? '');
        $greeting = $name !== ''
            ? "Good to see you, {$name}. Let's begin."
            : (config('coach_lines.greetings')[1] ?? 'Good to see you. Let\'s begin.');

        $focus = $this->focusLine($user);

        return [
            'type' => 'greeting',
            'say' => trim($greeting.' '.$focus),
            'focus' => $focus,
        ];
    }

    /**
     * One-sentence focus derived from the user's most frequent failure
     * pattern — same signal DailyReviewController surfaces.
     */
    private function focusLine(User $user): string
    {
        $top = UserFailurePattern::where('user_id', $user->id)
            ->orderByDesc('occurrence_count')
            ->first();
        if (! $top) {
            return 'Today we sharpen the fundamentals.';
        }
        $kind = str_replace('_', ' ', $top->pattern_kind);
        $phase = $top->phase ?? 'middlegame';

        return "Today we work on {$kind} in the {$phase}.";
    }

    /**
     * The interactive centerpiece: the worst mistake from the user's most
     * recent analyzed game, posed as a find-the-better-move quiz answered on
     * the board. The frontend reconstructs the position from pgn + ply.
     */
    private function quizStep(User $user): ?array
    {
        $game = $user->games()
            ->whereNotNull('analyzed_at')
            ->whereNotNull('pgn')
            ->orderByDesc('played_at')
            ->first();
        if (! $game || ! $game->user_color) {
            return null;
        }

        $worst = MoveAnalysis::where('game_id', $game->id)
            ->where('color', $game->user_color)
            ->whereIn('classification', ['inaccuracy', 'mistake', 'blunder', 'miss'])
            ->whereNotNull('best_move_san')
            ->orderByDesc('cp_loss')
            ->first();
        if (! $worst) {
            return null;
        }

        $ply = $worst->move_number * 2 - ($worst->color === 'white' ? 1 : 0);
        $spoken = $this->sanToSpeech($worst->move_san);

        return [
            'type' => 'quiz',
            'say' => "Let's look at your last game. On move {$worst->move_number} you played {$spoken}."
                .' There was something better — show me on the board.',
            'payload' => [
                'game_id' => $game->id,
                'ply' => $ply,
                'pgn' => $game->pgn,
                'played_san' => $worst->move_san,
                'best_san' => $worst->best_move_san,
                'pv' => $worst->best_move_line,
                'classification' => $worst->classification,
                'cp_loss' => $worst->cp_loss,
                'player_color' => $game->user_color,
                'opening_name' => $game->opening_name,
            ],
        ];
    }

    /**
     * 2–3 tactics from the homework set. Puzzles use the Lichess convention:
     * moves[0] is the opponent's setup move, moves[1] is the player's answer —
     * the frontend applies the setup and quizzes the response.
     *
     * @param  array{puzzles: \Illuminate\Support\Collection}  $homework
     * @return list<array<string, mixed>>
     */
    private function puzzleSteps(array $homework, int $count): array
    {
        $transition = config('coach_lines.transitions')[1] ?? 'Now, a quick exercise.';
        $more = config('coach_lines.transitions')[2] ?? 'One more, then we wrap up.';

        $steps = [];
        foreach ($homework['puzzles']->take($count)->values() as $i => $puzzle) {
            $steps[] = [
                'type' => 'puzzle',
                'say' => $i === 0 ? $transition : $more,
                'payload' => [
                    'id' => $puzzle->id,
                    'fen' => $puzzle->fen,
                    'moves' => $puzzle->moves,
                    'rating' => $puzzle->rating,
                    'themes' => $puzzle->themes,
                ],
            ];
        }

        return $steps;
    }

    /**
     * One due SM-2 pattern review, if any — graded through the existing
     * POST /homework/pattern/{id}/review endpoint.
     *
     * @param  array{pattern_reviews: \Illuminate\Support\Collection}  $homework
     */
    private function patternReviewStep(array $homework): ?array
    {
        $due = $homework['pattern_reviews']->first();
        if (! $due || ! $due->failurePattern) {
            return null;
        }
        $kind = str_replace('_', ' ', $due->failurePattern->pattern_kind);

        return [
            'type' => 'pattern_review',
            'say' => (config('coach_lines.transitions')[3] ?? 'Let\'s check your homework positions.')
                ." This pattern keeps coming back: {$kind}. Tell me how well you remember it.",
            'payload' => [
                'schedule_id' => $due->id,
                'pattern_kind' => $due->failurePattern->pattern_kind,
                'phase' => $due->failurePattern->phase,
                'occurrences' => $due->failurePattern->occurrence_count,
                'sample' => $due->failurePattern->sample_position_json,
            ],
        ];
    }

    private function wrapupStep(User $user): array
    {
        $say = config('coach_lines.wrapups')[0] ?? 'Good session today. Same time tomorrow.';

        $preview = null;
        $plan = ImprovementPlan::where('user_id', $user->id)->first();
        $label = $plan?->next_action_json['label'] ?? null;
        if (is_string($label) && $label !== '') {
            $preview = "Next up on your journey: {$label}.";
        }

        return [
            'type' => 'wrapup',
            'say' => $preview ? "{$say} {$preview}" : $say,
            'tomorrow_preview' => $preview,
        ];
    }

    /**
     * Make SAN speakable: "Qxe4+" → "queen takes e4, check". Mirrors the
     * frontend's sanToSpeech so spoken lines never read raw notation.
     */
    private function sanToSpeech(string $san): string
    {
        if ($san === 'O-O') {
            return 'castles kingside';
        }
        if ($san === 'O-O-O') {
            return 'castles queenside';
        }

        $pieces = ['K' => 'king', 'Q' => 'queen', 'R' => 'rook', 'B' => 'bishop', 'N' => 'knight'];
        $out = $san;
        $suffix = '';
        if (str_ends_with($out, '#')) {
            $suffix = ', checkmate';
            $out = substr($out, 0, -1);
        } elseif (str_ends_with($out, '+')) {
            $suffix = ', check';
            $out = substr($out, 0, -1);
        }

        $piece = $pieces[$out[0] ?? ''] ?? null;
        if ($piece) {
            $out = substr($out, 1);
        } else {
            $piece = 'pawn';
        }

        $action = str_contains($out, 'x') ? 'takes' : 'to';
        $out = str_replace('x', '', $out);

        if (str_contains($out, '=')) {
            [$square, $promo] = explode('=', $out, 2);
            $promoName = $pieces[$promo] ?? 'queen';

            return "pawn {$action} {$square}, promoting to {$promoName}{$suffix}";
        }

        return "{$piece} {$action} {$out}{$suffix}";
    }
}
