<?php

namespace App\Services;

use App\Models\ContentDraft;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Drafts academy content via Gemini, then writes to content_drafts (status =
 * pending_review). chess-domain-expert agent reviews drafts in the admin UI
 * before publishing.
 */
class ContentGenerationService
{
    public function draftLesson(string $topic, string $eloBand): ContentDraft
    {
        $prompt = "Write a chess lesson on '{$topic}' targeted at Elo band {$eloBand}. " .
            "Use plain markdown. Include 2-3 example positions as FEN inside ```fen blocks. " .
            "Cover: concept, why it matters, 2-3 examples, common mistakes, mini-exercise. Keep under 600 words.";
        $body = $this->callGemini($prompt, 1200) ?? $this->fallbackLesson($topic, $eloBand);

        return ContentDraft::create([
            'kind' => 'lesson',
            'topic' => $topic,
            'elo_band' => $eloBand,
            'payload' => ['markdown' => $body],
        ]);
    }

    public function draftQuiz(string $topic, string $eloBand, int $questionCount = 5): ContentDraft
    {
        $prompt = "Generate {$questionCount} chess quiz questions on '{$topic}' for Elo band {$eloBand}. " .
            "Mix best-move, motif-naming, and plan-selection. " .
            "Return STRICT JSON only — an array of objects with keys: kind (best_move|motif|plan), prompt, fen, options (array of strings or null), correct_index (int) or correct_san (string), explanation.";
        $raw = $this->callGemini($prompt, 1200) ?? '[]';
        $questions = $this->safeJson($raw);

        return ContentDraft::create([
            'kind' => 'quiz',
            'topic' => $topic,
            'elo_band' => $eloBand,
            'payload' => ['questions' => $questions],
        ]);
    }

    public function selectPuzzleSet(string $theme, string $eloBand, int $count = 20): ContentDraft
    {
        $bandRanges = [
            'foundations' => [400, 1100],
            'improver' => [1100, 1500],
            'club' => [1500, 1900],
            'tournament' => [1900, 2400],
        ];
        [$min, $max] = $bandRanges[$eloBand] ?? [1000, 1800];

        $puzzleIds = \App\Models\Puzzle::query()
            ->where('themes', 'like', "%{$theme}%")
            ->whereBetween('rating', [$min, $max])
            ->inRandomOrder()
            ->limit($count)
            ->pluck('id');

        return ContentDraft::create([
            'kind' => 'puzzle_set',
            'topic' => $theme,
            'elo_band' => $eloBand,
            'payload' => ['puzzle_ids' => $puzzleIds, 'theme' => $theme, 'count' => $puzzleIds->count()],
        ]);
    }

    public function draftGuidedStudy(int $masterGameId, string $eloBand): ContentDraft
    {
        $prompt = "Write an annotated walkthrough of master game id {$masterGameId} for Elo {$eloBand} students. " .
            "Pause at 5-7 critical moments — for each: position, the master's move, the strategic idea, a guided question for the student.";
        $body = $this->callGemini($prompt, 1500) ?? $this->fallbackGuidedStudy($masterGameId, $eloBand);

        return ContentDraft::create([
            'kind' => 'guided_study',
            'topic' => "master_game_{$masterGameId}",
            'elo_band' => $eloBand,
            'payload' => ['markdown' => $body, 'master_game_id' => $masterGameId],
        ]);
    }

    /**
     * Deterministic, offline lesson scaffold used when Gemini is unavailable.
     * Produces a real, structured markdown draft an admin can refine — never a
     * bare "placeholder" string. Drafts still land in content_drafts for review.
     */
    private function fallbackLesson(string $topic, string $eloBand): string
    {
        $t = trim($topic);

        return <<<MD
        # {$t}

        > Draft scaffold (generated offline — review and expand before publishing). Target Elo band: **{$eloBand}**.

        ## The idea
        Explain what **{$t}** is in one or two plain sentences, as if to a student who has just reached this level.

        ## Why it matters
        Connect {$t} to results on the board: what does a player who understands it do differently, and what mistakes does it prevent?

        ## Worked examples
        Add 2–3 positions and walk through them. Replace the starting position below with real teaching positions.

        ```fen
        rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1
        ```

        ## Common mistakes
        - List the most frequent error students make with {$t}.
        - Note the warning sign that tells them they are about to make it.

        ## Mini-exercise
        Pose one position and ask the student to find the move or plan that applies {$t}.
        MD;
    }

    /**
     * Deterministic, offline guided-study scaffold (Gemini unavailable).
     */
    private function fallbackGuidedStudy(int $masterGameId, string $eloBand): string
    {
        return <<<MD
        # Guided study — master game #{$masterGameId}

        > Draft scaffold (generated offline — review and expand before publishing). Target Elo band: **{$eloBand}**.

        Walk through this master game and pause at 5–7 critical moments. For each, fill in:

        1. **The position** — what both sides want.
        2. **The master's move** — what was actually played.
        3. **The strategic idea** — why it works.
        4. **A guided question** — ask the student what they would consider before reading on.

        Aim for the moments where the evaluation or the plan shifts, not every move.
        MD;
    }

    private function callGemini(string $prompt, int $maxTokens): ?string
    {
        $apiKey = config('services.gemini.key');
        if (! $apiKey) return null;
        $model = config('services.gemini.model', 'gemini-2.0-flash');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        try {
            $resp = Http::timeout(30)->post($url, [
                'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                'generationConfig' => ['maxOutputTokens' => $maxTokens, 'temperature' => 0.5],
            ]);
        } catch (\Throwable $e) {
            Log::warning('ContentGen Gemini error', ['error' => $e->getMessage()]);
            return null;
        }
        if (! $resp->successful()) return null;
        $text = $resp->json('candidates.0.content.parts.0.text', '');
        return $text !== '' ? $text : null;
    }

    private function safeJson(string $raw): array
    {
        $raw = trim($raw);
        // strip code fences if Gemini wrapped JSON
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $raw);
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
