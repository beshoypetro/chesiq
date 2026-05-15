<?php

namespace App\Services;

use App\Models\Game;
use App\Models\TeacherConversation;
use App\Models\TeacherFact;
use App\Models\TeacherMessage;
use App\Models\User;
use App\Models\UserFailurePattern;
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

    public function reply(User $user, TeacherConversation $conv, string $studentMessage, ?string $modeOverride): TeacherMessage
    {
        $mode = in_array($modeOverride, self::MODES, true) ? $modeOverride : $this->detectMode($studentMessage);

        TeacherMessage::create([
            'conversation_id' => $conv->id,
            'role' => 'student',
            'mode' => $mode,
            'content' => $studentMessage,
            'created_at' => now(),
        ]);

        $context = $this->buildContext($user, $conv, $studentMessage);
        $reply = $this->callGemini($context, $mode);

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
            $this->summarizeAndExtractFacts($conv);
        }

        return $msg;
    }

    public function teacherFullContext(User $user, string $query, ?TeacherConversation $conv): array
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

        // Memory recall — past conversation summaries (cosine similarity placeholder; embedding work in v2).
        $pastSummaries = TeacherConversation::where('user_id', $user->id)
            ->when($conv, fn ($q) => $q->where('id', '!=', $conv->id))
            ->whereNotNull('summary_text')
            ->orderByDesc('last_message_at')
            ->limit(3)
            ->pluck('summary_text');

        $recentTurns = $conv
            ? $conv->messages()->orderByDesc('created_at')->limit(8)->get()->reverse()->values()
            : collect();

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
            'query' => $query,
        ];
    }

    private function buildContext(User $user, TeacherConversation $conv, string $studentMessage): array
    {
        return $this->teacherFullContext($user, $studentMessage, $conv);
    }

    private function detectMode(string $message): string
    {
        $m = mb_strtolower($message);
        if (preg_match('/\b(why|how come|what if|is it because)\b/', $m)) return 'socratic';
        if (preg_match('/\b(but|i think|i disagree|actually|prove|wrong)\b/', $m)) return 'debate';
        return 'tutor';
    }

    private function callGemini(array $ctx, string $mode): string
    {
        $apiKey = config('services.gemini.key');
        if (! $apiKey) return $this->fallback($ctx['query']);

        $system = self::BASE_SYSTEM . "\n\n" . self::MODE_PROMPTS[$mode];
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

        $text = trim((string) $resp->json('candidates.0.content.parts.0.text', ''));
        return $text !== '' ? $text : $this->fallback($ctx['query']);
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
            $sections[] = "Past conversation summaries:\n- " . implode("\n- ", $past->all());
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

        $sections[] = "Student's current message: " . $ctx['query'];

        return implode("\n\n", $sections);
    }

    private function fallback(string $query): string
    {
        return "Tell me more about what you're trying to figure out — give me a position or a recent game we can look at together.";
    }

    private function summarizeAndExtractFacts(TeacherConversation $conv): void
    {
        // Lightweight summary fallback when no Gemini key — concatenates last 6 student turns.
        $messages = $conv->messages()->orderBy('created_at')->get();
        $lastStudent = $messages->where('role', 'student')->slice(-6)->pluck('content')->implode(' | ');
        $conv->update(['summary_text' => mb_substr($lastStudent, 0, 1000)]);
    }
}
