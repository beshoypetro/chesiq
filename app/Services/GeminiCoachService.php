<?php

namespace App\Services;

use App\Models\CoachCache;
use App\Models\OpeningLine;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiCoachService
{
    /**
     * The system prompt below carries four calibrated examples (best,
     * inaccuracy, blunder, brilliant) so Gemini gets concrete tone +
     * vocabulary anchors. Removing them caused noticeably more generic
     * "good move" filler in the Tier-1 tests.
     */
    private const SYSTEM_PROMPT = <<<'PROMPT'
You are Alex, a warm and encouraging chess coach in a one-on-one session with a student. Sessions can be opening practice, post-game review, or explaining a single move's rationale. Match your response to the context the user message gives you.

Voice and tone:
- Speak in 1–2 short sentences for opening practice and hints; 3–4 short sentences (50–80 words) for post-game move commentary.
- Use "we" instead of "you" — "we want the bishop on the long diagonal" not "you should put the bishop on the long diagonal".
- Be specific. Name squares, pieces, threats, pawn structure, king safety, tempo. Never generic platitudes.
- Natural spoken tone — your reply will be read aloud by text-to-speech. Plain prose only. No emojis, no markdown, no lists, no headings.
- Never write SAN notation like "Nf6" or "Bxh7+". Say "the knight to f6" or "the bishop captures on h7 with check".
- The student has already heard the move and the rating word — do NOT repeat them.

Adapt to the student:
- The user prompt may include the student's rating tier (beginner / intermediate / advanced / expert), style archetype, repertoire deviation flag, and master-game popularity for book moves. Use these to calibrate language and depth — beginners need concrete piece names and threats, advanced players can hear about long-term structural plans.
- For an attacking-style player (Tal, Kasparov), lean into tactical and dynamic ideas. For a positional one (Petrosian, Karpov), highlight structure, prophylaxis, and small advantages.

Verdict-specific obligations:
- best / brilliant: name the concrete idea (development, attack, conversion, tempo gain, structural improvement) AND what it denies the opponent.
- good: confirm the sound reason, briefly note whether something cleaner existed.
- book: explain the strategic plan behind the mainline. If popularity is given, you may cite it ("by far the main reply").
- inaccuracy / mistake: explain the specific issue, then describe the idea behind the engine's preferred move (without dictating the full variation).
- blunder / miss: name the concrete tactical motif (pin, fork, discovered attack, hanging piece, back-rank weakness, mate threat). Then describe what the engine's move defends or threatens.

Examples (each is one complete reply):
[Best move, opening practice]
"We're staking out the center while keeping the king safe before any committal pawn breaks. That extra tempo will matter once the queenside is contested."

[Inaccuracy, game review]
"This trade releases the tension a beat early — once the dark-squared bishop is gone, the f6 square loses its main defender. The engine prefers keeping pieces on with rook to e8, which keeps pressure on the e-file and lets us decide the trade on our terms."

[Blunder, game review]
"This drops the b2 pawn because the queen has nothing protecting it once the bishop steps away. Pulling the rook to b1 first holds the pawn and frees the bishop to redeploy on its own time. Always check what just walked away from a pawn before moving the next piece."

[Brilliant, game review]
"This sacrifice pulls the king onto the dark squares where every piece is already pointing — the rook lift on the next move is what makes it work. It looks reckless because the queen is briefly out of play, but the king has no safe escape squares and white converts on move three. Patterns like this come from studying Tal — the king matters more than the material count."
PROMPT;

    /**
     * Returns the system prompt for an outbound Gemini call, optionally
     * prepended with a trainer persona line. Controllers wire the persona
     * by setting `_persona` on the $ctx array — typically pulled from
     * `config('trainers')[$user->selected_trainer_id]['persona']`.
     *
     * The persona is not part of the cache key (see cacheKey()) because the
     * same coached idea should be reused regardless of which character voiced
     * it — persona shapes tone, not factual content.
     */
    private function systemPrompt(array $ctx): string
    {
        $persona = $ctx['_persona'] ?? null;
        if (! is_string($persona) || $persona === '') {
            return self::SYSTEM_PROMPT;
        }
        return $persona . "\n\n" . self::SYSTEM_PROMPT;
    }

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

        $model = $this->modelFor($ctx);
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        try {
            $response = Http::timeout(10)->post($url, [
                'system_instruction' => [
                    'parts' => [['text' => $this->systemPrompt($ctx)]],
                ],
                'contents' => [[
                    'role' => 'user',
                    'parts' => [['text' => $this->buildPrompt($ctx)]],
                ]],
                'generationConfig' => [
                    'maxOutputTokens' => 200,
                    'temperature' => 0.7,
                    // Gemini 2.5 flash spends maxOutputTokens on hidden "thinking";
                    // disabling it frees the whole budget for the visible answer
                    // (otherwise short coaching replies truncate at MAX_TOKENS).
                    'thinkingConfig' => ['thinkingBudget' => 0],
                ],
            ]);
        } catch (\Throwable) {
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

        $studentLine = $this->studentLine($ctx);

        if ($correct) {
            return implode("\n", array_filter([
                "We are training the {$opening} ({$line}).",
                "It is move {$moveNum} for {$color}.",
                $studentLine,
                "The student just played the correct book move ({$attempt}).",
                "FEN before the move: {$fen}",
                'In one short sentence, reinforce WHY this move is the right idea here.',
            ]));
        }

        return implode("\n", array_filter([
            "We are training the {$opening} ({$line}).",
            "It is move {$moveNum} for {$color}.",
            $studentLine,
            "The student attempted {$attempt} but the book move is {$expected}.",
            "FEN before the move: {$fen}",
            'In 1–2 short sentences, explain gently what the issue is with the attempted move and nudge toward the better idea — but do not spell out the exact move.',
        ]));
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
            // Personalization tier — same FEN+move gets calibrated to skill.
            $ctx['rating_tier'] ?? '',
            $ctx['style_archetype'] ?? '',
        ];

        return hash('sha256', implode('|', $payload));
    }

    /**
     * Post-game commentary for a single move during game review.
     *
     * The student already heard the SAN and classification word read aloud
     * before this text plays, so the reply explains WHY — the concrete
     * strategic/tactical reason — in Alex's voice (3–4 sentences, TTS-ready).
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

        $model = $this->modelFor($ctx);
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        try {
            $response = Http::timeout(20)->post($url, [
                'system_instruction' => [
                    'parts' => [['text' => $this->systemPrompt($ctx)]],
                ],
                'contents' => [[
                    'role' => 'user',
                    'parts' => [['text' => $this->buildCommentaryPrompt($ctx)]],
                ]],
                'generationConfig' => [
                    'maxOutputTokens' => 350,
                    'temperature' => 0.7,
                    'thinkingConfig' => ['thinkingBudget' => 0],
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

        // Personalization
        $studentLine = $this->studentLine($ctx);
        $deviation = ! empty($ctx['repertoire_deviation_here']);
        $popularity = $ctx['masters_popularity_pct'] ?? null;
        $motifs = is_array($ctx['tactical_motifs'] ?? null) ? $ctx['tactical_motifs'] : [];
        $candidates = is_array($ctx['candidate_moves'] ?? null) ? $ctx['candidate_moves'] : [];
        $tablebase = $ctx['tablebase_note'] ?? null;

        $lines = [];
        $lines[] = 'We are reviewing a past game together in post-game analysis.';
        $lines[] = "It is move {$moveNum} for {$color}.";
        if ($studentLine) $lines[] = $studentLine;
        if ($opening) $lines[] = "Opening: {$opening}.";
        if ($deviation) $lines[] = "This move is the FIRST move where the student deviated from their stored repertoire.";
        if ($prev) $lines[] = "Opponent's previous move: {$prev}.";
        $lines[] = "The student played: {$san}.";
        $lines[] = "Engine classification: {$cls}.";
        if ($fenB) $lines[] = "Position BEFORE the move (FEN): {$fenB}";
        if ($fenA) $lines[] = "Position AFTER the move (FEN): {$fenA}";
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
        if ($popularity !== null && $cls === 'book') {
            $lines[] = "Master-game popularity for the played move from this position: {$popularity}%.";
        }
        if (! empty($motifs)) {
            $lines[] = 'Tactical facts detected on the board (use to ground your analysis, do not list them verbatim): ' . implode('; ', $motifs);
        }
        if (! empty($candidates)) {
            $candStr = [];
            foreach ($candidates as $c) {
                $cs = $c['san'] ?? null;
                $ce = isset($c['eval']) ? round((float) $c['eval'] / 100, 2) : null;
                if ($cs !== null && $ce !== null) {
                    $candStr[] = "{$cs} ({$ce})";
                }
            }
            if (! empty($candStr)) {
                $lines[] = 'Top engine candidate moves with evaluations: ' . implode(', ', $candStr);
            }
        }
        if ($tablebase) {
            $lines[] = "Tablebase fact (this is a 7-piece-or-fewer endgame): {$tablebase}";
        }

        $lines[] = '';
        $lines[] = 'Your task:';
        $lines[] = $this->commentaryTaskFor($cls, $san, $best);
        $lines[] = 'Constraints:';
        $lines[] = '- 3 to 4 short sentences, total around 50–80 words.';
        $lines[] = '- The student has ALREADY heard the move and the rating word — do NOT repeat either.';
        $lines[] = '- Reference concrete squares, pieces, threats, pawn structure, king safety, or specific tactical motifs you see in the FEN.';
        $lines[] = '- If there is a clearly better move, explain the idea behind it and the short-term follow-up (one or two moves deep), without dumping the full variation.';
        $lines[] = '- Calibrate vocabulary to the student\'s rating tier — beginners get concrete piece-and-square language, advanced players hear positional concepts.';
        $lines[] = '- Plain prose only. No lists, no headings, no markdown, no emojis. Speak the moves out loud ("the knight to f3", not "Nf3").';

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
        // Cache by stable position+verdict and personalization buckets. Per-user
        // weakness themes intentionally sit outside the cache key — they live
        // in the end-of-game synthesis and chat surfaces, not commentary.
        $payload = [
            $ctx['fen_before'] ?? '',
            $ctx['san'] ?? '',
            $ctx['classification'] ?? '',
            $ctx['best_move_san'] ?? '',
            $ctx['rating_tier'] ?? '',
            $ctx['style_archetype'] ?? '',
            ! empty($ctx['repertoire_deviation_here']) ? 'dev' : 'nodev',
            $ctx['masters_popularity_pct'] ?? '',
            // Tactical-motif fingerprint (T2.5) — same motifs ⇒ same coaching.
            implode(',', is_array($ctx['tactical_motifs'] ?? null) ? $ctx['tactical_motifs'] : []),
            // Tablebase note fingerprint (T2.7).
            $ctx['tablebase_note'] ?? '',
            // Multi-PV candidate fingerprint (T2.6) — fingerprint by SAN list
            // only; we accept that small eval drift across runs reuses cache.
            implode(',', array_map(
                fn ($c) => is_array($c) ? ((string) ($c['san'] ?? '')) : '',
                is_array($ctx['candidate_moves'] ?? null) ? $ctx['candidate_moves'] : []
            )),
        ];

        return hash('sha256', implode('|', $payload));
    }

    /**
     * Tiered model routing (T3.10): use Flash for routine verdicts that
     * mostly need calibration; upgrade to Pro for the moments that matter.
     * Falls back to flash if no override is configured.
     */
    private function modelFor(array $ctx): string
    {
        $cls = strtolower((string) ($ctx['classification'] ?? ''));
        $heavy = in_array($cls, ['blunder', 'mistake', 'brilliant', 'miss'], true);
        $proModel = config('services.gemini.pro_model');
        if ($heavy && $proModel) {
            return $proModel;
        }
        return config('services.gemini.model', 'gemini-2.0-flash');
    }

    private function studentLine(array $ctx): ?string
    {
        $tier = $ctx['rating_tier'] ?? null;
        $arch = $ctx['style_archetype'] ?? null;
        if (! $tier && ! $arch) {
            return null;
        }
        $parts = [];
        if ($tier) $parts[] = "rating tier: {$tier}";
        if ($arch) $parts[] = "playing style closest to {$arch}";
        return 'Student profile — ' . implode(', ', $parts) . '.';
    }

    /**
     * F005: In-game hint — given a position FEN, return a one-sentence strategic goal.
     * Does NOT reveal the best move, only the idea.
     *
     * Persona, when provided, is folded into both the system prompt (so the
     * hint sounds like the trainer) and the cache key (so two trainers don't
     * share a cached line that was first generated for a different persona).
     */
    public function hint(string $prompt, ?string $persona = null): string
    {
        $personaKey = is_string($persona) && $persona !== '' ? hash('sha256', $persona) : 'none';
        $key = 'hint|' . $personaKey . '|' . hash('sha256', $prompt);

        $hit = CoachCache::where('cache_key', $key)->first();
        if ($hit) {
            return $hit->response_text;
        }

        $apiKey = config('services.gemini.key');
        if (! $apiKey) {
            return 'Focus on piece activity and king safety.';
        }

        $model = config('services.gemini.model', 'gemini-2.0-flash');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        try {
            $response = Http::timeout(10)->post($url, [
                'system_instruction' => [
                    'parts' => [['text' => $this->systemPrompt(['_persona' => $persona])]],
                ],
                'contents' => [[
                    'role' => 'user',
                    'parts' => [['text' => $prompt]],
                ]],
                'generationConfig' => [
                    'maxOutputTokens' => 80,
                    'temperature' => 0.7,
                    'thinkingConfig' => ['thinkingBudget' => 0],
                ],
            ]);
        } catch (\Throwable) {
            return 'Focus on piece activity and king safety.';
        }

        if (! $response->successful()) {
            return 'Focus on piece activity and king safety.';
        }

        $text = trim((string) $response->json('candidates.0.content.parts.0.text', ''));
        if ($text === '') {
            return 'Focus on piece activity and king safety.';
        }

        CoachCache::create([
            'cache_key' => $key,
            'response_text' => $text,
        ]);

        return $text;
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
                // generateLineRationales runs server-side per opening line, not
                // per user — persona doesn't apply here.
                'system_instruction' => [
                    'parts' => [['text' => $this->systemPrompt([])]],
                ],
                'contents' => [[
                    'role' => 'user',
                    'parts' => [['text' => $prompt]],
                ]],
                'generationConfig' => [
                    'maxOutputTokens' => 1200,
                    'temperature' => 0.6,
                    'thinkingConfig' => ['thinkingBudget' => 0],
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

    /**
     * End-of-game synthesis (T2.8): three-bullet review covering top theme,
     * biggest missed idea, and a drill recommendation. Cached per
     * (game_id, user_id) — every game gets one synthesis.
     */
    public function gameSummary(array $ctx): array
    {
        $key = 'summary|game-' . ($ctx['game_id'] ?? 'x') . '|user-' . ($ctx['user_id'] ?? 'x');
        $hit = CoachCache::where('cache_key', $key)->first();
        if ($hit) {
            $decoded = json_decode($hit->response_text, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $apiKey = config('services.gemini.key');
        if (! $apiKey) {
            return $this->summaryFallback();
        }

        $model = config('services.gemini.pro_model') ?? config('services.gemini.model', 'gemini-2.0-flash');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        try {
            $response = Http::timeout(30)->post($url, [
                'system_instruction' => [
                    'parts' => [['text' => $this->systemPrompt($ctx)]],
                ],
                'contents' => [[
                    'role' => 'user',
                    'parts' => [['text' => $this->buildSummaryPrompt($ctx)]],
                ]],
                'generationConfig' => [
                    'maxOutputTokens' => 600,
                    'temperature' => 0.5,
                    'thinkingConfig' => ['thinkingBudget' => 0],
                    'responseMimeType' => 'application/json',
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Gemini game summary HTTP error', ['error' => $e->getMessage()]);
            return $this->summaryFallback();
        }

        if (! $response->successful()) {
            Log::warning('Gemini game summary non-success', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 600),
            ]);
            return $this->summaryFallback();
        }

        $text = trim((string) $response->json('candidates.0.content.parts.0.text', ''));
        $parsed = json_decode($text, true);
        if (! is_array($parsed) || ! isset($parsed['top_theme'], $parsed['biggest_missed_idea'], $parsed['drill_recommendation'])) {
            Log::warning('Gemini game summary malformed JSON', ['raw' => $text]);
            return $this->summaryFallback();
        }

        $out = [
            'top_theme' => trim((string) $parsed['top_theme']),
            'biggest_missed_idea' => trim((string) $parsed['biggest_missed_idea']),
            'drill_recommendation' => trim((string) $parsed['drill_recommendation']),
            'drill_motif' => isset($parsed['drill_motif']) ? trim((string) $parsed['drill_motif']) : null,
        ];

        CoachCache::create([
            'cache_key' => $key,
            'response_text' => json_encode($out),
        ]);

        return $out;
    }

    private function buildSummaryPrompt(array $ctx): string
    {
        $opening = $ctx['opening_name'] ?? 'unknown opening';
        $color = $ctx['user_color'] ?? 'white';
        $accuracy = $ctx['user_accuracy'] ?? null;
        $result = $ctx['result'] ?? '?';
        $blunders = is_array($ctx['notable_moves'] ?? null) ? $ctx['notable_moves'] : [];
        $weakThemes = is_array($ctx['weak_themes'] ?? null) ? $ctx['weak_themes'] : [];
        $blunderMotifs = is_array($ctx['frequent_blunder_motifs'] ?? null) ? $ctx['frequent_blunder_motifs'] : [];
        $tier = $ctx['rating_tier'] ?? null;
        $arch = $ctx['style_archetype'] ?? null;

        $lines = [];
        $lines[] = 'Post-game synthesis. Produce a JSON object with three fields and an optional motif tag.';
        $lines[] = "Opening: {$opening}.";
        $lines[] = "Student color: {$color}.";
        $lines[] = "Result: {$result}.";
        if ($accuracy !== null) $lines[] = "Student accuracy: {$accuracy}%.";
        if ($tier) $lines[] = "Rating tier: {$tier}.";
        if ($arch) $lines[] = "Style archetype: {$arch}.";

        if (! empty($blunders)) {
            $lines[] = 'Notable mistakes (move number, classification, played, best, cp_loss in pawns):';
            foreach (array_slice($blunders, 0, 8) as $m) {
                $mn = $m['move_number'] ?? '?';
                $cl = $m['classification'] ?? '?';
                $pl = $m['san'] ?? '?';
                $bs = $m['best_move_san'] ?? '?';
                $cp = isset($m['cp_loss']) ? round((float) $m['cp_loss'] / 100, 2) : 0;
                $lines[] = "  · move {$mn}: {$cl}, played {$pl}, best {$bs}, lost {$cp} pawns";
            }
        }
        if (! empty($weakThemes)) {
            $lines[] = 'Recent puzzle weakness themes (last 30 days): ' . implode(', ', $weakThemes) . '.';
        }
        if (! empty($blunderMotifs)) {
            $lines[] = 'Recent recurring patterns in this student\'s games: ' . implode('; ', $blunderMotifs) . '.';
        }

        $lines[] = '';
        $lines[] = 'Return ONLY this exact JSON shape (no prose, no code fences):';
        $lines[] = '{"top_theme":"...","biggest_missed_idea":"...","drill_recommendation":"...","drill_motif":"..."}';
        $lines[] = 'Field meanings:';
        $lines[] = '- top_theme: the single biggest takeaway from this game in 1 sentence.';
        $lines[] = '- biggest_missed_idea: the most important specific tactical or strategic chance the student missed, in 1–2 sentences. Reference the move number and concrete pieces/squares.';
        $lines[] = '- drill_recommendation: 1 sentence advising what kind of training position would best fix this. Speak in plain prose ("we should drill positions where the king sits on f1 with the queens still on").';
        $lines[] = '- drill_motif: a single short tag like "hanging-piece" or "back-rank" that names the pattern. Use lowercase-with-dashes. Optional — set to null if no clear single motif.';
        $lines[] = 'Same voice rules as always: warm, "we" not "you", spoken-aloud SAN, no emojis or markdown.';

        return implode("\n", $lines);
    }

    private function summaryFallback(): array
    {
        return [
            'top_theme' => 'Solid game overall — the engine flagged a few specific moments worth revisiting.',
            'biggest_missed_idea' => 'Look back at the moves marked as mistakes and see if you can spot the tactical idea you missed at the time.',
            'drill_recommendation' => 'Try a few themed puzzles tonight focused on the patterns you found tricky here.',
            'drill_motif' => null,
        ];
    }

    /**
     * Conversational chat (T3.9). messages[] is an array of
     * ['role' => 'user'|'model', 'text' => string]. Position context (FEN,
     * eval, candidate moves) is injected as a leading system-style note in
     * the first user turn.
     */
    public function chat(array $ctx): string
    {
        $apiKey = config('services.gemini.key');
        if (! $apiKey) {
            return "I'm offline right now — try again once the coach service is configured.";
        }

        $messages = is_array($ctx['messages'] ?? null) ? $ctx['messages'] : [];
        if (empty($messages)) {
            return 'Ask me anything about this position or the game.';
        }

        $contextNote = $this->buildChatContextNote($ctx);

        $contents = [];
        foreach ($messages as $i => $m) {
            $role = ($m['role'] ?? 'user') === 'model' ? 'model' : 'user';
            $text = (string) ($m['text'] ?? '');
            if ($text === '') continue;
            // Prepend context note to the first user turn so Gemini sees it
            // before answering, but it never appears in the visible transcript.
            if ($i === 0 && $role === 'user' && $contextNote !== '') {
                $text = $contextNote . "\n\nStudent question: " . $text;
            }
            $contents[] = [
                'role' => $role,
                'parts' => [['text' => $text]],
            ];
        }
        if (empty($contents)) {
            return 'Ask me anything about this position or the game.';
        }

        $model = config('services.gemini.pro_model') ?? config('services.gemini.model', 'gemini-2.0-flash');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        try {
            $response = Http::timeout(25)->post($url, [
                'system_instruction' => [
                    'parts' => [['text' => $this->systemPrompt($ctx)]],
                ],
                'contents' => $contents,
                'generationConfig' => [
                    'maxOutputTokens' => 500,
                    'temperature' => 0.7,
                    'thinkingConfig' => ['thinkingBudget' => 0],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Gemini chat HTTP error', ['error' => $e->getMessage()]);
            return "I lost my connection — try that again.";
        }

        if (! $response->successful()) {
            Log::warning('Gemini chat non-success', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 600),
            ]);
            return "Something went wrong on my end — try that again in a moment.";
        }

        $text = trim((string) $response->json('candidates.0.content.parts.0.text', ''));
        return $text !== '' ? $text : "I'm not sure how to answer that — can you rephrase?";
    }

    private function buildChatContextNote(array $ctx): string
    {
        $parts = [];
        if (! empty($ctx['fen'])) $parts[] = 'Current FEN: ' . $ctx['fen'] . '.';
        if (! empty($ctx['opening_name'])) $parts[] = 'Opening: ' . $ctx['opening_name'] . '.';
        if (isset($ctx['eval_pawns'])) $parts[] = 'Engine eval: ' . round((float) $ctx['eval_pawns'], 2) . ' pawns.';
        if (! empty($ctx['best_move_san'])) $parts[] = 'Engine best move: ' . $ctx['best_move_san'] . '.';
        if (! empty($ctx['rating_tier'])) $parts[] = 'Student rating tier: ' . $ctx['rating_tier'] . '.';
        if (! empty($ctx['style_archetype'])) $parts[] = 'Student style: ' . $ctx['style_archetype'] . '.';
        if (! empty($ctx['weak_themes']) && is_array($ctx['weak_themes'])) {
            $parts[] = 'Recent weakness themes: ' . implode(', ', $ctx['weak_themes']) . '.';
        }
        if (! empty($ctx['frequent_blunder_motifs']) && is_array($ctx['frequent_blunder_motifs'])) {
            $parts[] = 'Recurring patterns: ' . implode('; ', $ctx['frequent_blunder_motifs']) . '.';
        }
        if (! empty($ctx['recent_moves']) && is_array($ctx['recent_moves'])) {
            $parts[] = 'Last few moves played: ' . implode(' ', $ctx['recent_moves']) . '.';
        }
        if (! empty($ctx['memory_notes']) && is_array($ctx['memory_notes'])) {
            $notes = array_slice(array_filter($ctx['memory_notes'], fn ($s) => is_string($s) && $s !== ''), 0, 6);
            if (! empty($notes)) {
                $parts[] = 'Long-term coach notes about this student (use to inform tone and depth, do not quote back): ' . implode(' | ', $notes);
            }
        }
        if (empty($parts)) return '';
        return "[Context for the coach — do not quote this back, use it to ground the answer]\n" . implode(' ', $parts);
    }
}
