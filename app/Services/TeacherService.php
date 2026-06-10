<?php

namespace App\Services;

use App\Models\Game;
use App\Models\LlmCallLog;
use App\Models\TeacherConversation;
use App\Models\TeacherFact;
use App\Models\TeacherMessage;
use App\Models\User;
use App\Models\UserFailurePattern;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Conversational tutor — three modes (tutor / socratic / debate), persistent
 * memory via teacher_conversations + teacher_facts. Anti-sycophancy directives
 * are baked into the system prompt; eval suite (T2) ensures the teacher pushes
 * back when grounded.
 */
class TeacherService
{
    public const MODES = ['tutor', 'socratic', 'debate'];

    private const BASE_SYSTEM = <<<'PROMPT'
You are Alex, an experienced chess teacher in a one-on-one session. You speak in plain, natural prose — your replies will be read aloud. No markdown, no lists, no SAN like "Nf6" — say "knight to f6".

Core conduct:
- DISAGREE WHEN GROUNDED. Do not validate weak moves to be encouraging. If the student claims a move was good but it lost material or weakened the king, say so plainly and explain.
- DEMAND EVIDENCE. If the student asserts something ("I'm bad at endgames"), ask for the most recent example or look at the data block in this prompt. Do not just nod.
- BE CONCRETE. Name pieces, squares, threats, plans. Never say "you should improve your position" — say *what* improves it.
- MEMORY MATTERS. The conversation summary block contains durable facts about this student. Use them to keep continuity. If something they say contradicts a stored fact, surface it.
- USE THE STUDENT'S DATA. The recent activity block includes failure patterns and recent games. Reference specifics, not generalities.
- KEEP IT SHORT. 2-4 sentences for tutor, 1-2 sentences for socratic counter-questions, 3-5 sentences for debate.
PROMPT;

    private const MODE_PROMPTS = [
        'tutor' => "Mode: TUTOR. Explain the concept clearly, give a worked example tied to the student's data when possible, then check understanding with one short question at the end.",
        'socratic' => "Mode: SOCRATIC. Do NOT explain directly. Counter with one pointed question that leads the student to discover the answer. Keep it under 30 words.",
        'debate' => "Mode: DEBATE. Steel-man what the student said, then disagree with concrete evidence (a piece is hanging, a pattern from their games, a master game). Propose an empirical test they can run.",
    ];

    public function startConversation(User $user): TeacherConversation
    {
        return TeacherConversation::create([
            'user_id' => $user->id,
            'started_at' => now(),
        ]);
    }

    public function reply(User $user, TeacherConversation $conv, string $studentMessage, ?string $modeOverride, ?string $fen = null, ?int $moduleId = null, ?int $activityId = null): TeacherMessage
    {
        $mode = $this->resolveMode($user, $studentMessage, $modeOverride);

        TeacherMessage::create([
            'conversation_id' => $conv->id,
            'role' => 'student',
            'mode' => $mode,
            'content' => $studentMessage,
            'created_at' => now(),
        ]);

        $context = $this->buildContext($user, $conv, $studentMessage, $fen, $moduleId, $activityId);
        $reply = $this->callGemini($context, $mode, $user->trainerPersona(), $user->id);

        $msg = TeacherMessage::create([
            'conversation_id' => $conv->id,
            'role' => 'teacher',
            'mode' => $mode,
            'content' => $reply,
            'created_at' => now(),
        ]);

        $conv->update(['last_message_at' => now()]);

        // Summarize every 6 exchanges (12 messages).
        if ($conv->messages()->count() % 12 === 0) {
            $this->summarizeAndExtractFacts($user, $conv);
        }

        return $msg;
    }

    /**
     * Resolve the reply mode for this turn:
     *   1. an explicit, valid mode_override, else
     *   2. a STRONG keyword signal from the message (socratic / debate), else
     *   3. the selected trainer's configured default_mode, else
     *   4. 'tutor'.
     */
    private function resolveMode(User $user, string $studentMessage, ?string $modeOverride): string
    {
        if (in_array($modeOverride, self::MODES, true)) {
            return $modeOverride;
        }

        $detected = $this->detectMode($studentMessage);
        if ($detected !== null) {
            return $detected;
        }

        $trainerDefault = config("trainers.{$user->selected_trainer_id}.default_mode");
        if (in_array($trainerDefault, self::MODES, true)) {
            return $trainerDefault;
        }

        return 'tutor';
    }

    public function teacherFullContext(User $user, string $query, ?TeacherConversation $conv, ?string $fen = null, ?int $moduleId = null, ?int $activityId = null): array
    {
        $patterns = UserFailurePattern::where('user_id', $user->id)
            ->orderByDesc('occurrence_count')
            ->limit(3)
            ->get(['pattern_kind', 'phase', 'opening_eco', 'occurrence_count']);

        $recentGames = Game::where('user_id', $user->id)
            ->whereNotNull('analyzed_at')
            ->orderByDesc('played_at')
            ->limit(5)
            ->get(['id', 'opening_name', 'eco_code', 'result', 'user_color', 'white_accuracy', 'black_accuracy']);

        $facts = TeacherFact::where('user_id', $user->id)
            ->whereNull('superseded_at')
            ->orderByDesc('confidence')
            ->limit(8)
            ->pluck('fact_text');

        // Memory recall — rank past-conversation summaries by lexical relevance
        // to what the user is asking *now*, not just by recency. Deterministic
        // and $0: a bag-of-words cosine over normalized tokens (see
        // rankSummariesByRelevance), with recency as the tie-break. Pull a wide
        // recency window as the candidate pool, then keep the few that actually
        // discuss the current query. (Embedding-vector recall is the future
        // upgrade; this lexical pass is the pragmatic, no-API stand-in.)
        $summaryCandidates = TeacherConversation::where('user_id', $user->id)
            ->when($conv, fn ($q) => $q->where('id', '!=', $conv->id))
            ->whereNotNull('summary_text')
            ->orderByDesc('last_message_at')
            ->limit(20)
            ->pluck('summary_text');
        $pastSummaries = $this->rankSummariesByRelevance($query, $summaryCandidates->all(), 3);

        $recentTurns = $conv
            ? $conv->messages()->orderByDesc('created_at')->limit(8)->get()->reverse()->values()
            : collect();

        // Academy progress — single join query; silently empty if tables are missing or user has no progress.
        $academyContext = $this->buildAcademyContext($user, $moduleId, $activityId);

        return [
            'identity' => [
                'name' => $user->name,
                'placement_elo' => $user->placement_elo,
                'placement_track' => $user->placement_track,
                'style_archetype' => json_decode((string) $user->style_profile_json, true)['closest_archetype'] ?? null,
            ],
            'recent_activity' => [
                'top_patterns' => $patterns,
                'recent_games' => $recentGames,
            ],
            'memory' => [
                'facts' => $facts,
                'past_summaries' => $pastSummaries,
            ],
            'recent_turns' => $recentTurns,
            'fen' => $fen,
            'academy' => $academyContext,
            'query' => $query,
        ];
    }

    /**
     * Stopwords stripped before measuring summary↔query overlap — high-frequency
     * glue words carry no topical signal and would otherwise inflate similarity.
     *
     * @var array<string, true>
     */
    private const RECALL_STOPWORDS = [
        'the' => true, 'and' => true, 'for' => true, 'with' => true, 'that' => true,
        'this' => true, 'you' => true, 'your' => true, 'are' => true, 'was' => true,
        'how' => true, 'what' => true, 'why' => true, 'when' => true, 'can' => true,
        'about' => true, 'from' => true, 'have' => true, 'has' => true, 'but' => true,
        'not' => true, 'they' => true, 'their' => true, 'them' => true, 'should' => true,
        'would' => true, 'could' => true, 'into' => true, 'onto' => true, 'than' => true,
        'then' => true, 'there' => true, 'here' => true, 'some' => true, 'any' => true,
        'all' => true, 'out' => true, 'get' => true, 'got' => true, 'tell' => true,
        'were' => true, 'been' => true, 'being' => true, 'does' => true, 'did' => true,
    ];

    /**
     * Rank candidate conversation summaries by lexical relevance to the current
     * query and return the top {@param $limit} summary strings.
     *
     * Deterministic and $0 — a bag-of-words cosine over normalized, stopword-
     * filtered tokens. Candidates arrive recency-ordered (newest first); PHP's
     * stable sort keeps that order as the tie-break, so equally-relevant (or
     * all-zero-relevance) summaries fall back to pure recency — exactly the old
     * behaviour. This surfaces the summary that talks about what the user is
     * asking now instead of merely the most recent chat. Embedding-vector recall
     * is the eventual upgrade; this is the no-API stand-in.
     *
     * @param  array<int, string|null>  $candidates  summary_text values, recency-desc
     * @return array<int, string>
     */
    private function rankSummariesByRelevance(string $query, array $candidates, int $limit = 3): array
    {
        $clean = array_values(array_filter(
            array_map(fn ($s) => is_string($s) ? trim($s) : '', $candidates),
            fn ($s) => $s !== '',
        ));

        // Nothing to order — return as-is (already recency-ordered).
        if (count($clean) <= 1) {
            return $clean;
        }

        $queryVec = $this->termFrequencies($query);
        if ($queryVec === []) {
            // No usable query terms — preserve the legacy recency ordering.
            return array_slice($clean, 0, $limit);
        }

        $scored = [];
        foreach ($clean as $i => $summary) {
            $scored[] = [
                'i' => $i, // original (recency) index — the stable tie-break
                'text' => $summary,
                'score' => $this->cosineSimilarity($queryVec, $this->termFrequencies($summary)),
            ];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score'] ?: $a['i'] <=> $b['i']);

        return array_map(fn ($row) => $row['text'], array_slice($scored, 0, $limit));
    }

    /**
     * Lowercased term-frequency map for a chunk of text: split on non-alphanumerics,
     * drop tokens shorter than 3 chars and stopwords.
     *
     * @return array<string, int>
     */
    private function termFrequencies(string $text): array
    {
        $tokens = preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $freq = [];
        foreach ($tokens as $tok) {
            if (strlen($tok) < 3 || isset(self::RECALL_STOPWORDS[$tok])) {
                continue;
            }
            $freq[$tok] = ($freq[$tok] ?? 0) + 1;
        }

        return $freq;
    }

    /**
     * Cosine similarity between two term-frequency vectors (0.0–1.0). Returns 0
     * when either vector is empty.
     *
     * @param  array<string, int>  $a
     * @param  array<string, int>  $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $dot = 0;
        foreach ($a as $term => $count) {
            if (isset($b[$term])) {
                $dot += $count * $b[$term];
            }
        }
        if ($dot === 0) {
            return 0.0;
        }

        $magA = sqrt(array_sum(array_map(fn ($v) => $v * $v, $a)));
        $magB = sqrt(array_sum(array_map(fn ($v) => $v * $v, $b)));

        return $dot / ($magA * $magB);
    }

    /**
     * Build the academy context block from module_progress + modules + courses + tracks.
     * Defensive: returns an empty array if tables are missing, user has no rows, or any
     * exception occurs — never breaks the reply.
     *
     * When $moduleId is provided the caller is explicitly asking about that module (e.g.
     * "Ask the teacher about this module" button), so we surface it as the focal point
     * regardless of the user's general progress state.
     *
     * @return array{in_progress: array|null, recently_completed: array<int, array>, focal_module: array|null}
     */
    private function buildAcademyContext(User $user, ?int $moduleId = null, ?int $activityId = null): array
    {
        $result = [
            'in_progress' => null,
            'recently_completed' => [],
            'focal_module' => null,
        ];

        try {
            // Single query: progress rows joined to modules → courses → tracks.
            $rows = DB::table('module_progress as mp')
                ->join('modules as m', 'm.id', '=', 'mp.module_id')
                ->join('courses as c', 'c.id', '=', 'm.course_id')
                ->join('tracks as t', 't.id', '=', 'c.track_id')
                ->where('mp.user_id', $user->id)
                ->orderByDesc('mp.started_at')
                ->limit(6)
                ->get([
                    'mp.module_id',
                    'mp.started_at',
                    'mp.completed_at',
                    'mp.activities_completed',
                    'mp.activities_total',
                    'm.name as module_name',
                    'm.overview as module_overview',
                    'c.name as course_name',
                    't.name as track_name',
                ]);

            if ($rows->isEmpty()) {
                // Still resolve focal_module if moduleId was provided.
                if ($moduleId !== null) {
                    $result['focal_module'] = $this->fetchFocalModule($moduleId, $activityId);
                }
                return $result;
            }

            // The most-recently-started, not-yet-completed module is "in progress".
            $inProgressRow = $rows->first(fn ($r) => $r->completed_at === null);
            if ($inProgressRow) {
                $result['in_progress'] = [
                    'module_id' => $inProgressRow->module_id,
                    'module_name' => $inProgressRow->module_name,
                    'track_name' => $inProgressRow->track_name,
                    'activities_completed' => (int) $inProgressRow->activities_completed,
                    'activities_total' => (int) $inProgressRow->activities_total,
                ];
            }

            // Recent completions (up to 3), excluding the in-progress module.
            $result['recently_completed'] = $rows
                ->filter(fn ($r) => $r->completed_at !== null)
                ->take(3)
                ->map(fn ($r) => [
                    'module_name' => $r->module_name,
                    'track_name' => $r->track_name,
                ])
                ->values()
                ->all();

        } catch (\Throwable $e) {
            Log::warning('Teacher academy context query failed', ['error' => $e->getMessage()]);
            // Return whatever we have so far (likely the empty defaults).
        }

        // Focal module: explicitly requested module (e.g. "ask about this module" button).
        if ($moduleId !== null) {
            $result['focal_module'] = $this->fetchFocalModule($moduleId, $activityId);
        }

        return $result;
    }

    /**
     * Fetch a single module's name + overview (and optionally an activity title)
     * for the focal-module feature. Returns null on any failure.
     *
     * @return array{module_name: string, module_overview: string|null, activity_title: string|null}|null
     */
    private function fetchFocalModule(int $moduleId, ?int $activityId): ?array
    {
        try {
            $mod = DB::table('modules')->where('id', $moduleId)->first(['name', 'overview']);
            if (! $mod) return null;

            $activityTitle = null;
            if ($activityId !== null) {
                $act = DB::table('activities')
                    ->where('id', $activityId)
                    ->where('module_id', $moduleId)
                    ->value('title');
                $activityTitle = $act ?: null;
            }

            return [
                'module_name' => $mod->name,
                'module_overview' => $mod->overview,
                'activity_title' => $activityTitle,
            ];
        } catch (\Throwable $e) {
            Log::warning('Teacher focal module fetch failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function buildContext(User $user, TeacherConversation $conv, string $studentMessage, ?string $fen = null, ?int $moduleId = null, ?int $activityId = null): array
    {
        return $this->teacherFullContext($user, $studentMessage, $conv, $fen, $moduleId, $activityId);
    }

    /**
     * Returns a STRONG mode signal from message keywords, or null when no
     * keyword matches (caller then falls back to the trainer default).
     */
    private function detectMode(string $message): ?string
    {
        $m = mb_strtolower($message);
        if (preg_match('/\b(why|how come|what if|is it because)\b/', $m)) return 'socratic';
        if (preg_match('/\b(but|i think|i disagree|actually|prove|wrong)\b/', $m)) return 'debate';
        return null;
    }

    private function callGemini(array $ctx, string $mode, ?string $persona = null, ?int $userId = null): string
    {
        $apiKey = config('services.gemini.key');
        if (! $apiKey) return $this->fallback($ctx['query']);

        // Persona is prepended so the trainer's voice colors every reply.
        // Conduct rules below it still apply — persona shapes tone, not behavior.
        $system = ($persona ? $persona . "\n\n" : '')
            . self::BASE_SYSTEM . "\n\n" . self::MODE_PROMPTS[$mode];
        $userPrompt = $this->renderUserPrompt($ctx);

        $model = config('services.gemini.model', 'gemini-2.0-flash');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        try {
            $resp = Http::timeout(20)->post($url, [
                'system_instruction' => ['parts' => [['text' => $system]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $userPrompt]]]],
                'generationConfig' => ['maxOutputTokens' => 350, 'temperature' => 0.7],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Teacher Gemini error', ['error' => $e->getMessage()]);
            return $this->fallback($ctx['query']);
        }

        if (! $resp->successful()) return $this->fallback($ctx['query']);

        $usage = $resp->json('usageMetadata', []);
        $this->logLlmCall($userId, $model, 'teacher_reply', $usage);

        $text = trim((string) $resp->json('candidates.0.content.parts.0.text', ''));
        return $text !== '' ? $text : $this->fallback($ctx['query']);
    }

    /**
     * Best-effort telemetry — never let logging break a reply.
     */
    private function logLlmCall(?int $userId, string $model, string $endpoint, array $usage): void
    {
        try {
            LlmCallLog::create([
                'user_id' => $userId,
                'endpoint' => $endpoint,
                'model' => $model,
                'tokens_in' => $usage['promptTokenCount'] ?? null,
                'tokens_out' => $usage['candidatesTokenCount'] ?? null,
                'cost_estimate' => null,
                'cache_hit' => false,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Teacher LLM log failed', ['error' => $e->getMessage()]);
        }
    }

    private function renderUserPrompt(array $ctx): string
    {
        $sections = [];

        $id = $ctx['identity'];
        $sections[] = "Student: {$id['name']}, placement Elo " . ($id['placement_elo'] ?? 'unknown') .
            ", track " . ($id['placement_track'] ?? 'unset') .
            ", style " . ($id['style_archetype'] ?? 'mixed') . ".";

        $patterns = $ctx['recent_activity']['top_patterns'];
        if (count($patterns) > 0) {
            $lines = [];
            foreach ($patterns as $p) {
                $lines[] = "- {$p['pattern_kind']} ({$p['phase']}, {$p['occurrence_count']}×)";
            }
            $sections[] = "Top failure patterns:\n" . implode("\n", $lines);
        }

        $games = $ctx['recent_activity']['recent_games'];
        if (count($games) > 0) {
            $lines = [];
            foreach ($games as $g) {
                $acc = $g['user_color'] === 'white' ? $g['white_accuracy'] : $g['black_accuracy'];
                $lines[] = "- " . ($g['opening_name'] ?? '?') . ", {$g['result']}, " . ($acc !== null ? round($acc, 1) . "%" : "no analysis");
            }
            $sections[] = "Recent games:\n" . implode("\n", $lines);
        }

        $facts = $ctx['memory']['facts'];
        if (count($facts) > 0) {
            $sections[] = "Stored facts about this student:\n- " . implode("\n- ", $facts->all());
        }

        $past = $ctx['memory']['past_summaries'];
        if (count($past) > 0) {
            $sections[] = "Past conversation summaries:\n- " . implode("\n- ", $past);
        }

        $turns = $ctx['recent_turns'];
        if (count($turns) > 0) {
            $lines = [];
            foreach ($turns as $t) {
                $role = $t->role === 'student' ? 'Student' : 'You';
                $lines[] = "{$role}: {$t->content}";
            }
            $sections[] = "Recent dialogue:\n" . implode("\n", $lines);
        }

        if (! empty($ctx['fen'])) {
            $sections[] = "The student is currently looking at this position (FEN): {$ctx['fen']}. Refer to it concretely when relevant.";
        }

        // Academy progress block — only rendered when non-empty.
        $academyNote = $this->renderAcademyNote($ctx['academy'] ?? []);
        if ($academyNote !== '') {
            $sections[] = $academyNote;
        }

        $sections[] = "Student's current message: " . $ctx['query'];

        return implode("\n\n", $sections);
    }

    /**
     * Render the academy context block as a concise natural-language note for
     * the LLM prompt. Returns an empty string when there is nothing to show.
     *
     * @param array{in_progress?: array|null, recently_completed?: array<int, array>, focal_module?: array|null} $academy
     */
    public function renderAcademyNote(array $academy): string
    {
        $lines = [];

        // Focal module (explicit "ask about this module" request) — shown first.
        $focal = $academy['focal_module'] ?? null;
        if (is_array($focal) && ($focal['module_name'] ?? '') !== '') {
            $focusLine = "The student is asking specifically about the '{$focal['module_name']}' module";
            if (! empty($focal['module_overview'])) {
                $focusLine .= " (overview: {$focal['module_overview']})";
            }
            if (! empty($focal['activity_title'])) {
                $focusLine .= ", activity: '{$focal['activity_title']}'";
            }
            $focusLine .= ". Connect your answer to this module's content.";
            $lines[] = $focusLine;
        }

        // General progress note.
        $inProgress = $academy['in_progress'] ?? null;
        if (is_array($inProgress) && ($inProgress['module_name'] ?? '') !== '') {
            $done = (int) ($inProgress['activities_completed'] ?? 0);
            $total = (int) ($inProgress['activities_total'] ?? 0);
            $progressStr = $total > 0 ? "{$done}/{$total} activities done" : "{$done} activities done";
            $progressLine = "Academy: the student is working through the '{$inProgress['module_name']}' module"
                . " in the {$inProgress['track_name']} track ({$progressStr}).";

            $completed = $academy['recently_completed'] ?? [];
            if (count($completed) > 0) {
                $names = array_map(fn ($c) => $c['module_name'], $completed);
                $progressLine .= " Recently completed: " . implode(', ', $names) . ".";
            }

            $progressLine .= " When relevant, connect your advice to what they're studying and nudge them toward their next activity.";
            $lines[] = $progressLine;
        } elseif (empty($lines)) {
            // No focal module and no in-progress module — nothing to add.
            $completed = $academy['recently_completed'] ?? [];
            if (count($completed) > 0) {
                $names = array_map(fn ($c) => $c['module_name'], $completed);
                $lines[] = "Academy: the student has recently completed: " . implode(', ', $names) . ". When relevant, reference what they've learned.";
            }
        }

        return implode("\n", $lines);
    }

    private function fallback(string $query): string
    {
        return "Tell me more about what you're trying to figure out — give me a position or a recent game we can look at together.";
    }

    private function summarizeAndExtractFacts(User $user, TeacherConversation $conv): void
    {
        $messages = $conv->messages()->orderBy('created_at')->get();

        // Try the real LLM-backed summary + fact extraction. Anything that goes
        // wrong (no key, network failure, non-2xx, unparseable JSON) falls
        // through to the deterministic concat fallback so tests stay green.
        $result = $this->summarizeViaGemini($user, $conv, $messages);

        if ($result !== null && ($result['summary'] ?? '') !== '') {
            $conv->update(['summary_text' => mb_substr($result['summary'], 0, 1000)]);
            $this->persistFacts($user, $conv, $result['facts'] ?? []);
            return;
        }

        // Fallback: concatenate last 6 student turns, create no facts.
        $lastStudent = $messages->where('role', 'student')->slice(-6)->pluck('content')->implode(' | ');
        $conv->update(['summary_text' => mb_substr($lastStudent, 0, 1000)]);
    }

    /**
     * Calls Gemini for a JSON {summary, facts[]} payload. Returns null on any
     * failure (no key, exception, non-2xx, missing/unparseable JSON) so the
     * caller can use the deterministic fallback. Isolated so tests can stay
     * network-free: with no key configured it returns null immediately.
     *
     * @return array{summary:string,facts:array<int,string>}|null
     */
    private function summarizeViaGemini(User $user, TeacherConversation $conv, $messages): ?array
    {
        $apiKey = config('services.gemini.key');
        if (! $apiKey) return null;

        $transcript = $messages->map(function ($m) {
            $role = $m->role === 'student' ? 'Student' : 'Teacher';
            return "{$role}: {$m->content}";
        })->implode("\n");

        $system = <<<'SYS'
You distill a chess tutoring conversation into durable memory. Read the transcript and respond with STRICT JSON only, no markdown, matching this schema:
{"summary": "<2-4 sentence narrative about the STUDENT'S chess — recurring weaknesses, insights they reached, commitments they made. Not a transcript.>", "facts": ["<short atomic fact about the student>", ...]}
Rules: 1 to 5 facts, each a short standalone statement (e.g. "Struggles to convert rook endgames", "Plays the Caro-Kann as Black"). If nothing durable emerged, return an empty facts array. Output ONLY the JSON object.
SYS;

        $model = config('services.gemini.model', 'gemini-2.0-flash');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        try {
            $resp = Http::timeout(20)->post($url, [
                'system_instruction' => ['parts' => [['text' => $system]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $transcript]]]],
                'generationConfig' => [
                    'maxOutputTokens' => 400,
                    'temperature' => 0.3,
                    'responseMimeType' => 'application/json',
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Teacher summary Gemini error', ['error' => $e->getMessage()]);
            return null;
        }

        if (! $resp->successful()) return null;

        $this->logLlmCall($user->id, $model, 'teacher_summary', $resp->json('usageMetadata', []));

        $text = trim((string) $resp->json('candidates.0.content.parts.0.text', ''));
        if ($text === '') return null;

        $parsed = json_decode($text, true);
        if (! is_array($parsed) || ! isset($parsed['summary']) || ! is_string($parsed['summary'])) {
            return null;
        }

        $facts = [];
        if (isset($parsed['facts']) && is_array($parsed['facts'])) {
            foreach ($parsed['facts'] as $f) {
                if (is_string($f) && trim($f) !== '') {
                    $facts[] = trim(mb_substr($f, 0, 500));
                }
            }
        }

        return ['summary' => trim($parsed['summary']), 'facts' => array_slice($facts, 0, 5)];
    }

    /**
     * Persist 1–5 extracted facts. Before inserting each fact, supersede any
     * existing non-superseded fact whose normalized text closely matches
     * (case-insensitive equality or high similarity) so contradictions/dupes
     * don't accumulate.
     *
     * @param array<int,string> $facts
     */
    private function persistFacts(User $user, TeacherConversation $conv, array $facts): void
    {
        foreach ($facts as $factText) {
            $normalized = $this->normalizeFact($factText);
            if ($normalized === '') continue;

            $existing = TeacherFact::where('user_id', $user->id)
                ->whereNull('superseded_at')
                ->get(['id', 'fact_text']);

            foreach ($existing as $row) {
                if ($this->factsMatch($normalized, $this->normalizeFact($row->fact_text))) {
                    $row->update(['superseded_at' => now()]);
                }
            }

            TeacherFact::create([
                'user_id' => $user->id,
                'fact_text' => $factText,
                'confidence' => 0.7,
                'source_conversation_id' => $conv->id,
            ]);
        }
    }

    private function normalizeFact(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower($text)));
    }

    private function factsMatch(string $a, string $b): bool
    {
        if ($a === '' || $b === '') return false;
        if ($a === $b) return true;
        // Cheap similarity guard for near-duplicates / restatements.
        similar_text($a, $b, $percent);
        return $percent >= 80.0;
    }
}
