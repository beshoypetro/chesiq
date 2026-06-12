<?php

namespace App\Services;

use App\Models\Game;
use App\Models\User;
use App\Models\UserFailurePattern;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Generates a weekly study plan for a user. Calls Gemini with the user's
 * trainer persona, placement Elo, weakness profile, intents, and recent-game
 * summary, then returns a structured plan ready to persist into user_plans.
 *
 * Falls back to a heuristic plan when Gemini is unavailable so onboarding
 * never hangs on an LLM outage. CHESSIQ_V2_PLAN §15.
 */
class PlanGeneratorService
{
    public function generate(User $user): array
    {
        $trainerId = $user->selected_trainer_id ?? 'king';
        $trainer = config("trainers.{$trainerId}") ?? config('trainers.king');
        $intents = is_array($user->intents) ? $user->intents : [];

        $elo = $this->resolveElo($user);
        $patterns = $this->topPatterns($user);
        $games = $this->recentGames($user);

        $payload = $this->callGemini($trainer, $elo, $patterns, $games, $intents);

        return $payload ?? $this->fallbackPlan($trainer, $elo, $intents);
    }

    private function resolveElo(User $user): ?int
    {
        // placement_elo is the assessment output; fall back to chess.com avg if
        // we ever populate that. Keep this single-source so the plan reflects
        // exactly what the rest of the app sees.
        return (int) ($user->placement_elo ?? 1200);
    }

    /** @return array<int, array<string, mixed>> */
    private function topPatterns(User $user): array
    {
        return UserFailurePattern::where('user_id', $user->id)
            ->orderByDesc('occurrence_count')
            ->limit(5)
            ->get(['pattern_kind', 'phase', 'opening_eco', 'occurrence_count'])
            ->toArray();
    }

    /** @return array<int, array<string, mixed>> */
    private function recentGames(User $user): array
    {
        return Game::where('user_id', $user->id)
            ->whereNotNull('analyzed_at')
            ->orderByDesc('played_at')
            ->limit(5)
            ->get(['opening_name', 'result', 'user_color', 'white_accuracy', 'black_accuracy'])
            ->toArray();
    }

    private function callGemini(array $trainer, ?int $elo, array $patterns, array $games, array $intents): ?array
    {
        $apiKey = config('services.gemini.key');
        if (! $apiKey) {
            return null;
        }

        $system = $trainer['persona']."\n\n".
            "You are generating a 7-day study plan for a student. Output STRICT JSON only, ".
            "no prose, no markdown, exactly this shape:\n".
            '{"trainer_intro":"...","week_1":{"daily_focus":"...","tasks":'.
            '[{"day":"Mon","type":"puzzle_set|lesson|drill|review","label":"...","duration_min":10}]},'.
            '"tracks_enrolled":["..."],"next_review_in_days":7}'.
            "\nKeep daily_focus to one sentence. Tasks must be 7 entries (Mon-Sun). ".
            "Match difficulty to Elo ${elo}. Reflect the intents in task selection.";

        $userPrompt = json_encode([
            'student' => [
                'elo' => $elo,
                'intents' => $intents,
                'top_failure_patterns' => $patterns,
                'recent_games' => $games,
                'trainer_specialty' => $trainer['specialty'] ?? [],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $model = config('services.gemini.model', 'gemini-2.0-flash');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        try {
            $response = Http::timeout(30)->post($url, [
                'system_instruction' => ['parts' => [['text' => $system]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $userPrompt]]]],
                'generationConfig' => [
                    'maxOutputTokens' => 1200,
                    'temperature' => 0.6,
                    'responseMimeType' => 'application/json',
                    'thinkingConfig' => ['thinkingBudget' => 0],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('PlanGenerator Gemini error', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $text = (string) $response->json('candidates.0.content.parts.0.text', '');
        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function fallbackPlan(array $trainer, ?int $elo, array $intents): array
    {
        $name = $trainer['name'] ?? 'Your teacher';
        $intentsText = empty($intents) ? 'getting stronger overall' : implode(', ', array_map(
            fn ($i) => str_replace('_', ' ', $i),
            $intents,
        ));

        return [
            'trainer_intro' => "Welcome. I'm {$name}. Based on a roughly {$elo} Elo placement and your focus on {$intentsText}, here's a starter week — we'll tune it once we've played a few games together.",
            'week_1' => [
                'daily_focus' => 'Build the habit — 10 to 15 minutes a day beats a 2-hour Saturday.',
                'tasks' => [
                    ['day' => 'Mon', 'type' => 'puzzle_set', 'label' => 'Tactics warmup · 10 puzzles', 'duration_min' => 10],
                    ['day' => 'Tue', 'type' => 'lesson',     'label' => 'Pick a lesson from your level',   'duration_min' => 15],
                    ['day' => 'Wed', 'type' => 'drill',      'label' => 'Drill 3 endgame positions',       'duration_min' => 10],
                    ['day' => 'Thu', 'type' => 'puzzle_set', 'label' => 'Tactics · pin & fork sets',        'duration_min' => 10],
                    ['day' => 'Fri', 'type' => 'review',     'label' => 'Replay yesterday\'s game with the teacher', 'duration_min' => 15],
                    ['day' => 'Sat', 'type' => 'play',       'label' => 'Play 2 rapid games on chess.com', 'duration_min' => 30],
                    ['day' => 'Sun', 'type' => 'review',     'label' => 'Coach summary of Saturday\'s games', 'duration_min' => 15],
                ],
            ],
            'tracks_enrolled' => [],
            'next_review_in_days' => 7,
        ];
    }
}
