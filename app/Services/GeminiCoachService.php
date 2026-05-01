<?php

namespace App\Services;

use App\Models\CoachCache;
use App\Models\OpeningLine;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiCoachService
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
You are Alex, a warm and encouraging chess coach in a one-on-one session with a student.
The session may be opening practice, post-game review, or explaining a move's rationale. Respond in exactly 1–2 short sentences, matched to the context the user message gives you.

Rules:
- Use "we" instead of "you" ("we want to control the center", not "you should").
- Be specific and concrete — name squares, pieces, ideas. Never generic platitudes.
- For a wrong move: explain the issue AND briefly hint at the better idea, without spelling out the full move unless asked.
- For a correct or high-quality move: reinforce the concrete reason it works.
- For a poor move that was already played (review context): explain the strategic or tactical reason behind the rating.
- Natural spoken tone — this will be read aloud by text-to-speech.
- Never mention move notation like "Nf6" — say "the knight to f6" or "the knight move".
- Never use emojis, markdown, or lists. Plain text only.
PROMPT;

    public function coach(array $ctx): string
    {
        $key = $this->cacheKey($ctx);

        $hit = CoachCache::where('cache_key', $key)->first();
        if ($hit) {
            return $hit->response_text;
        }

        $apiKey = config('services.gemini.key');
        if (! $apiKey) {
            return $this->fallback($ctx);
        }

        $model = config('services.gemini.model', 'gemini-2.0-flash');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        try {
            $response = Http::timeout(10)->post($url, [
                'system_instruction' => [
                    'parts' => [['text' => self::SYSTEM_PROMPT]],
                ],
                'contents' => [[
                    'role' => 'user',
                    'parts' => [['text' => $this->buildPrompt($ctx)]],
                ]],
                'generationConfig' => [
                    'maxOutputTokens' => 150,
                    'temperature' => 0.7,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fallback($ctx);
        }

        if (! $response->successful()) {
            return $this->fallback($ctx);
        }

        $text = trim((string) $response->json('candidates.0.content.parts.0.text', ''));
        if ($text === '') {
            return $this->fallback($ctx);
        }

        CoachCache::create([
            'cache_key' => $key,
            'response_text' => $text,
        ]);

        return $text;
    }

    private function buildPrompt(array $ctx): string
    {
        $opening = $ctx['opening_name'] ?? 'this opening';
        $line = $ctx['line_name'] ?? 'this line';
        $fen = $ctx['fen'] ?? '';
        $attempt = $ctx['attempted_san'];
        $expected = $ctx['expected_san'];
        $moveNum = $ctx['move_number'] ?? '?';
        $color = $ctx['color'] ?? 'white';
        $correct = $ctx['correct'] ?? false;

        if ($correct) {
            return implode("\n", [
                "We are training the {$opening} ({$line}).",
                "It is move {$moveNum} for {$color}.",
                "The student just played the correct book move ({$attempt}).",
                "FEN before the move: {$fen}",
                'In one short sentence, reinforce WHY this move is the right idea here.',
            ]);
        }

        return implode("\n", [
            "We are training the {$opening} ({$line}).",
            "It is move {$moveNum} for {$color}.",
            "The student attempted {$attempt} but the book move is {$expected}.",
            "FEN before the move: {$fen}",
            'In 1–2 short sentences, explain gently what the issue is with the attempted move and nudge toward the better idea — but do not spell out the exact move.',
        ]);
    }

    private function fallback(array $ctx): string
    {
        if (! empty($ctx['correct'])) {
            return 'Good — that follows the main idea of the line.';
        }

        return "That's not quite the book move here. Let's think about which piece needs to come out next to keep the plan on track.";
    }

    private function cacheKey(array $ctx): string
    {
        $payload = [
            $ctx['fen'] ?? '',
            $ctx['attempted_san'] ?? '',
            $ctx['expected_san'] ?? '',
            $ctx['correct'] ?? false,
            $ctx['line_id'] ?? '',
        ];

        return hash('sha256', implode('|', $payload));
    }

    /**
     * Post-game commentary for a single move during game review.
     *
     * The student already heard the SAN and classification word read aloud
     * before this text plays, so the reply explains WHY — the concrete
     * strategic/tactical reason — in Alex's voice (1–2 sentences, TTS-ready).
     * Returns '' on failure so the UI silently skips commentary for that move.
     */
    public function commentary(array $ctx): string
    {
        $key = 'commentary|'.$this->commentaryCacheKey($ctx);

        $hit = CoachCache::where('cache_key', $key)->first();
        if ($hit) {
            return $hit->response_text;
        }

        $apiKey = config('services.gemini.key');
        if (! $apiKey) {
            return '';
        }

        $model = config('services.gemini.model', 'gemini-2.0-flash');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        try {
            $response = Http::timeout(15)->post($url, [
                'system_instruction' => [
                    'parts' => [['text' => self::SYSTEM_PROMPT]],
                ],
                'contents' => [[
                    'role' => 'user',
                    'parts' => [['text' => $this->buildCommentaryPrompt($ctx)]],
                ]],
                'generationConfig' => [
                    // 3–4 TTS-friendly sentences with real content need ~350 tokens;
                    // Gemini Flash prices per response token but these responses are
                    // permanently cached per-position, so the cost is one-shot.
                    'maxOutputTokens' => 350,
                    'temperature' => 0.7,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Gemini commentary HTTP error', ['error' => $e->getMessage()]);

            return '';
        }

        if (! $response->successful()) {
            Log::warning('Gemini commentary non-success', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 600),
            ]);

            return '';
        }

        $text = trim((string) $response->json('candidates.0.content.parts.0.text', ''));
        if ($text === '') {
            // Common cause: maxOutputTokens exhausted on a reasoning model before
            // any visible text was emitted — log the finishReason so we can spot it.
            Log::warning('Gemini commentary empty text', [
                'finishReason' => $response->json('candidates.0.finishReason'),
                'raw' => mb_substr($response->body(), 0, 600),
            ]);

            return '';
        }

        CoachCache::create([
            'cache_key' => $key,
            'response_text' => $text,
        ]);

        return $text;
    }

    private function buildCommentaryPrompt(array $ctx): string
    {
        $san = $ctx['san'];
        $cls = strtolower((string) ($ctx['classification'] ?? ''));
        $cpLoss = isset($ctx['cp_loss']) ? round((float) $ctx['cp_loss'] / 100, 2) : 0.0;
        $evB = isset($ctx['eval_before']) ? round((float) $ctx['eval_before'] / 100, 2) : null;
        $evA = isset($ctx['eval_after']) ? round((float) $ctx['eval_after'] / 100, 2) : null;
        $best = $ctx['best_move_san'] ?? null;
        $moveNum = $ctx['move_number'] ?? '?';
        $color = $ctx['player_color'] ?? 'white';
        $opening = $ctx['opening_name'] ?? null;
        $fenB = $ctx['fen_before'] ?? null;
        $fenA = $ctx['fen_after'] ?? null;
        $prev = $ctx['prev_san'] ?? null;
        $pv = is_array($ctx['pv'] ?? null) ? array_values(array_filter($ctx['pv'], fn ($x) => is_string($x))) : [];
        $pvStr = $pv ? implode(' ', array_slice($pv, 0, 8)) : null;

        $lines = [];
        $lines[] = 'We are reviewing a past game together in post-game analysis.';
        $lines[] = "It is move {$moveNum} for {$color}.";
        if ($opening) {
            $lines[] = "Opening: {$opening}.";
        }
        if ($prev) {
            $lines[] = "Opponent's previous move: {$prev}.";
        }
        $lines[] = "The student played: {$san}.";
        $lines[] = "Engine classification: {$cls}.";
        if ($fenB) {
            $lines[] = "Position BEFORE the move (FEN): {$fenB}";
        }
        if ($fenA) {
            $lines[] = "Position AFTER the move (FEN): {$fenA}";
        }
        if ($evB !== null && $evA !== null) {
            $lines[] = "Evaluation shifted from {$evB} to {$evA} pawns (positive favors white).";
        }
        if ($cpLoss > 0.05) {
            $lines[] = "Move cost approximately {$cpLoss} pawns relative to the engine's best continuation.";
        }
        if ($best && $best !== $san) {
            $lines[] = "Engine's preferred move in this position: {$best}.";
        }
        if ($pvStr) {
            $lines[] = "Engine's principal variation from the best move: {$pvStr}";
        }

        $lines[] = ''; // blank line before the task spec — helps Gemini separate data from instructions
        $lines[] = 'Your task:';
        $lines[] = $this->commentaryTaskFor($cls, $san, $best);
        $lines[] = 'Constraints:';
        $lines[] = '- 3 to 4 short sentences, total around 50–80 words.';
        $lines[] = '- The student has ALREADY heard the move and the rating word — do NOT repeat either.';
        $lines[] = '- Reference concrete squares, pieces, threats, pawn structure, king safety, or specific tactical motifs you see in the FEN.';
        $lines[] = '- If there is a clearly better move, explain the idea behind it and the short-term follow-up (one or two moves deep), without dumping the full variation.';
        $lines[] = '- Keep the tone warm and conversational for text-to-speech — say "the knight to f3" rather than "Nf3".';
        $lines[] = '- Plain prose only. No lists, no headings, no markdown, no emojis.';

        return implode("\n", $lines);
    }

    /**
     * Tailors the analytical angle to the engine's verdict so a brilliancy and a
     * blunder don't both get a generic "explain why this rating" instruction.
     */
    private function commentaryTaskFor(string $cls, string $san, ?string $best): string
    {
        return match ($cls) {
            'brilliant' => 'Explain what makes this move hard to find — the non-obvious tactical or positional idea behind it, and the concrete threat or structural gain it creates.',
            'best' => 'Explain why this is objectively the strongest continuation: the key idea it executes (development, attack, conversion) and what it denies the opponent.',
            'excellent',
            'good' => 'Explain the sound positional or tactical reason this move works, and briefly note whether a cleaner alternative existed.',
            'book' => 'Explain the strategic idea behind this mainline theory move — what plan it supports and what both sides are typically aiming for.',
            'inaccuracy' => "This move was slightly suboptimal. Explain what went slightly wrong and what {$best} would have achieved instead (a concrete gain in activity, structure, king safety, or tempo).",
            'mistake' => "This move was a real error. Explain the specific positional or tactical problem it creates, then describe the idea behind {$best} and what short-term follow-up the engine was going for.",
            'blunder' => "This move loses material or position decisively. Name the concrete tactical issue (pin, fork, skewer, hanging piece, back-rank weakness, etc.) and explain the idea of {$best} — what it defends or what threat it creates.",
            'miss' => 'The student missed a winning tactical opportunity. Explain what the winning idea was and why the move played failed to capitalize on it.',
            default => 'Explore this position for the student. Consider the main plans both sides have, whether the move played fits those plans, and whether a different move would have served the position better.',
        };
    }

    private function commentaryCacheKey(array $ctx): string
    {
        // FEN-before is the strongest uniqueness key — two different positions
        // where the student played Nf3 deserve different commentary. When FEN
        // is absent (older clients), fall back to the metadata shape.
        $payload = [
            $ctx['fen_before'] ?? '',
            $ctx['san'] ?? '',
            $ctx['classification'] ?? '',
            $ctx['best_move_san'] ?? '',
        ];

        return hash('sha256', implode('|', $payload));
    }

    /**
     * Generate coaching rationales for the student-side moves of an opening line.
     *
     * Returns an array of ['move_index' => int, 'san' => string, 'text' => string] entries.
     * Only includes the indices listed in $missingIndices — intended for on-demand backfill
     * when a line has no authored rationales yet. Returns [] on API failure so callers can
     * fall back cleanly.
     */
    public function generateLineRationales(OpeningLine $line, array $missingIndices): array
    {
        if (empty($missingIndices)) {
            return [];
        }

        $apiKey = config('services.gemini.key');
        if (! $apiKey) {
            return [];
        }

        $moves = $line->moves;
        if (! is_array($moves) || empty($moves)) {
            return [];
        }

        // Only generate for indices that are actually in range
        $missingIndices = array_values(array_filter(
            $missingIndices,
            fn ($i) => is_int($i) && $i >= 0 && $i < count($moves)
        ));
        if (empty($missingIndices)) {
            return [];
        }

        $movesList = '';
        foreach ($moves as $i => $san) {
            $marker = in_array($i, $missingIndices, true) ? ' << RATIONALE NEEDED' : '';
            $movesList .= "  {$i}: {$san}{$marker}\n";
        }

        $studentSide = match ($line->color) {
            'white' => 'white (even move indices)',
            'black' => 'black (odd move indices)',
            default => 'both sides',
        };

        $prompt = <<<PROMPT
Opening: {$line->opening_name} — {$line->line_name} (ECO {$line->eco})
Student plays: {$studentSide}
Move sequence (index: SAN):
{$movesList}
For each move marked "<< RATIONALE NEEDED", write a 1–2 sentence rationale explaining WHY that move is played in this line.
Rules for each rationale:
- Use "we" not "you" ("we develop the knight", not "you should").
- Be specific — name squares, pieces, ideas. No generic "good move" filler.
- Natural spoken tone — this will be read aloud by text-to-speech.
- Never cite move notation like "Nf3" inside the rationale; say "the knight to f3" or "the knight move".
- Plain text only — no markdown, emojis, or lists.
Return ONLY a JSON object with this exact shape (no prose, no code fences):
{"rationales":[{"move_index":0,"text":"..."},{"move_index":2,"text":"..."}]}
Include one entry for each NEEDED index.
PROMPT;

        $model = config('services.gemini.model', 'gemini-2.0-flash');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        try {
            $response = Http::timeout(30)->post($url, [
                'system_instruction' => [
                    'parts' => [['text' => self::SYSTEM_PROMPT]],
                ],
                'contents' => [[
                    'role' => 'user',
                    'parts' => [['text' => $prompt]],
                ]],
                'generationConfig' => [
                    'maxOutputTokens' => 1200,
                    'temperature' => 0.6,
                    'responseMimeType' => 'application/json',
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Gemini rationale generation failed', ['line_id' => $line->line_id, 'error' => $e->getMessage()]);

            return [];
        }

        if (! $response->successful()) {
            Log::warning('Gemini rationale generation non-success', [
                'line_id' => $line->line_id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        }

        $text = (string) $response->json('candidates.0.content.parts.0.text', '');
        $parsed = json_decode($text, true);
        if (! is_array($parsed) || ! isset($parsed['rationales']) || ! is_array($parsed['rationales'])) {
            Log::warning('Gemini rationale JSON malformed', ['line_id' => $line->line_id, 'raw' => $text]);

            return [];
        }

        $out = [];
        foreach ($parsed['rationales'] as $entry) {
            if (! isset($entry['move_index'], $entry['text'])) {
                continue;
            }
            $idx = (int) $entry['move_index'];
            if ($idx < 0 || $idx >= count($moves)) {
                continue;
            }
            $textOut = trim((string) $entry['text']);
            if ($textOut === '') {
                continue;
            }
            $out[] = [
                'move_index' => $idx,
                'san' => $moves[$idx],
                'text' => $textOut,
            ];
        }

        return $out;
    }
}
