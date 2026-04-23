<?php

namespace App\Services;

use App\Models\CoachCache;
use App\Models\OpeningLine;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiCoachService
{
    private const SYSTEM_PROMPT = <<<PROMPT
You are Alex, a warm and encouraging chess coach giving a one-on-one training session.
Your student just made a move while drilling a chess opening. Respond in exactly 1–2 short sentences.

Rules:
- Use "we" instead of "you" ("we want to control the center", not "you should").
- Be specific and concrete — name squares, pieces, ideas. Never generic platitudes.
- If the move is wrong, explain the issue AND briefly hint what the better idea is, without giving the full move away unless asked.
- If the move is correct, reinforce the reason the move works.
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
                    'role'  => 'user',
                    'parts' => [['text' => $this->buildPrompt($ctx)]],
                ]],
                'generationConfig' => [
                    'maxOutputTokens' => 150,
                    'temperature'     => 0.7,
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
            'cache_key'     => $key,
            'response_text' => $text,
        ]);

        return $text;
    }

    private function buildPrompt(array $ctx): string
    {
        $opening  = $ctx['opening_name'] ?? 'this opening';
        $line     = $ctx['line_name']    ?? 'this line';
        $fen      = $ctx['fen']          ?? '';
        $attempt  = $ctx['attempted_san'];
        $expected = $ctx['expected_san'];
        $moveNum  = $ctx['move_number']  ?? '?';
        $color    = $ctx['color']        ?? 'white';
        $correct  = $ctx['correct'] ?? false;

        if ($correct) {
            return implode("\n", [
                "We are training the {$opening} ({$line}).",
                "It is move {$moveNum} for {$color}.",
                "The student just played the correct book move ({$attempt}).",
                "FEN before the move: {$fen}",
                "In one short sentence, reinforce WHY this move is the right idea here.",
            ]);
        }

        return implode("\n", [
            "We are training the {$opening} ({$line}).",
            "It is move {$moveNum} for {$color}.",
            "The student attempted {$attempt} but the book move is {$expected}.",
            "FEN before the move: {$fen}",
            "In 1–2 short sentences, explain gently what the issue is with the attempted move and nudge toward the better idea — but do not spell out the exact move.",
        ]);
    }

    private function fallback(array $ctx): string
    {
        if (! empty($ctx['correct'])) {
            return "Good — that follows the main idea of the line.";
        }
        return "That's not quite the book move here. Let's think about which piece needs to come out next to keep the plan on track.";
    }

    private function cacheKey(array $ctx): string
    {
        $payload = [
            $ctx['fen']           ?? '',
            $ctx['attempted_san'] ?? '',
            $ctx['expected_san']  ?? '',
            $ctx['correct']       ?? false,
            $ctx['line_id']       ?? '',
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
        if (empty($missingIndices)) return [];

        $apiKey = config('services.gemini.key');
        if (! $apiKey) return [];

        $moves = $line->moves;
        if (! is_array($moves) || empty($moves)) return [];

        // Only generate for indices that are actually in range
        $missingIndices = array_values(array_filter(
            $missingIndices,
            fn ($i) => is_int($i) && $i >= 0 && $i < count($moves)
        ));
        if (empty($missingIndices)) return [];

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
        $url   = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        try {
            $response = Http::timeout(30)->post($url, [
                'system_instruction' => [
                    'parts' => [['text' => self::SYSTEM_PROMPT]],
                ],
                'contents' => [[
                    'role'  => 'user',
                    'parts' => [['text' => $prompt]],
                ]],
                'generationConfig' => [
                    'maxOutputTokens'   => 1200,
                    'temperature'       => 0.6,
                    'responseMimeType'  => 'application/json',
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Gemini rationale generation failed', ['line_id' => $line->line_id, 'error' => $e->getMessage()]);
            return [];
        }

        if (! $response->successful()) {
            Log::warning('Gemini rationale generation non-success', [
                'line_id' => $line->line_id,
                'status'  => $response->status(),
                'body'    => $response->body(),
            ]);
            return [];
        }

        $text = (string) $response->json('candidates.0.content.parts.0.text', '');
        $parsed = json_decode($text, true);
        if (!is_array($parsed) || !isset($parsed['rationales']) || !is_array($parsed['rationales'])) {
            Log::warning('Gemini rationale JSON malformed', ['line_id' => $line->line_id, 'raw' => $text]);
            return [];
        }

        $out = [];
        foreach ($parsed['rationales'] as $entry) {
            if (!isset($entry['move_index'], $entry['text'])) continue;
            $idx = (int) $entry['move_index'];
            if ($idx < 0 || $idx >= count($moves)) continue;
            $textOut = trim((string) $entry['text']);
            if ($textOut === '') continue;
            $out[] = [
                'move_index' => $idx,
                'san'        => $moves[$idx],
                'text'       => $textOut,
            ];
        }

        return $out;
    }
}
