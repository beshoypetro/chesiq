<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeFailurePatterns;
use App\Models\Game;
use App\Models\Lesson;
use App\Models\MoveAnalysis;
use App\Models\Repertoire;
use App\Services\CoachContextService;
use App\Services\GeminiCoachService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->games()->orderByDesc('played_at');

        if ($request->filled('time_class')) {
            $query->where('time_class', $request->time_class);
        }
        if ($request->filled('result')) {
            $query->where('result', $request->result);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('white_username', 'like', "%{$s}%")
                    ->orWhere('black_username', 'like', "%{$s}%")
                    ->orWhere('opening_name', 'like', "%{$s}%");
            });
        }

        $games = $query->paginate(20);

        return response()->json([
            'data' => $games->map(fn ($g) => $this->formatGame($g)),
            'meta' => [
                'total' => $games->total(),
                'current_page' => $games->currentPage(),
                'last_page' => $games->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, Game $game): JsonResponse
    {
        if ($game->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $game->load('moveAnalyses');

        return response()->json([
            'game' => $this->formatGame($game),
            'moves' => $game->moveAnalyses->map(fn ($m) => [
                'move_number' => $m->move_number,
                'color' => $m->color,
                'move_san' => $m->move_san,
                'best_move_san' => $m->best_move_san,
                'classification' => $m->classification,
                'cp_loss' => $m->cp_loss,
                'eval_before' => $m->eval_before,
                'eval_after' => $m->eval_after,
                'best_move_eval' => $m->best_move_eval,
                'explanation' => $m->explanation,
                'best_move_line' => $m->best_move_line,
            ]),
        ]);
    }

    public function saveAnalysis(Request $request, Game $game): JsonResponse
    {
        if ($game->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'moves' => 'required|array',
            'moves.*.move_number' => 'required|integer',
            'moves.*.color' => 'required|in:white,black',
            'moves.*.move_san' => 'required|string',
            'moves.*.best_move_san' => 'nullable|string',
            'moves.*.classification' => 'nullable|string',
            'moves.*.cp_loss' => 'nullable|integer',
            'moves.*.eval_before' => 'nullable|integer',
            'moves.*.eval_after' => 'nullable|integer',
            'moves.*.best_move_eval' => 'nullable|integer',
            'moves.*.explanation' => 'nullable|string',
            'moves.*.best_move_line' => 'nullable|array',
            'white_accuracy' => 'nullable|numeric',
            'black_accuracy' => 'nullable|numeric',
        ]);

        // Delete old analysis if re-analyzing
        $game->moveAnalyses()->delete();

        foreach ($data['moves'] as $m) {
            MoveAnalysis::create(array_merge(['game_id' => $game->id], $m));
        }

        $game->update([
            'white_accuracy' => $data['white_accuracy'] ?? null,
            'black_accuracy' => $data['black_accuracy'] ?? null,
            'analyzed_at' => now(),
        ]);

        AnalyzeFailurePatterns::dispatch($game->id);

        return response()->json(['message' => 'Analysis saved.']);
    }

    public function mistakePuzzle(Request $request, Game $game, int $moveIndex): JsonResponse
    {
        if ($game->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // $moveIndex is a 0-based ply index (half-moves).
        // move_analyses stores full move numbers (1-based) and color.
        // ply 0 = white move 1, ply 1 = black move 1, ply 2 = white move 2, etc.
        $fullMoveNumber = intdiv($moveIndex, 2) + 1;
        $color = ($moveIndex % 2 === 0) ? 'white' : 'black';

        $move = $game->moveAnalyses()
            ->where('move_number', $fullMoveNumber)
            ->where('color', $color)
            ->first();

        if (!$move) {
            return response()->json(['message' => 'Move not found.'], 404);
        }

        return response()->json([
            'move_index' => $moveIndex,
            'solution_san' => $move->best_move_san,
            'best_move_line' => $move->best_move_line ?? [],
            'classification' => $move->classification,
            'cp_loss' => $move->cp_loss,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pgn' => 'required|string',
            'white_username' => 'required|string|max:100',
            'black_username' => 'required|string|max:100',
            'user_color' => 'required|in:white,black',
            'result' => 'required|in:win,loss,draw',
            'time_class' => 'nullable|string|max:50',
            'time_control' => 'nullable|string|max:50',
            'source' => 'nullable|string|max:50',
            'skill_level' => 'nullable|integer|min:0|max:20',
        ]);

        $user = $request->user();

        $game = $user->games()->create([
            'pgn' => $data['pgn'],
            'white_username' => $data['white_username'],
            'black_username' => $data['black_username'],
            'user_color' => $data['user_color'],
            'result' => $data['result'],
            'time_class' => $data['time_class'] ?? 'rapid',
            'time_control' => $data['time_control'] ?? null,
            'source' => $data['source'] ?? 'bot',
            'played_at' => now(),
            'move_count' => (function (string $pgn): int {
                // Count SAN move tokens (e.g. e4, Nf3, O-O, exd5, e8=Q+) in PGN
                preg_match_all(
                    '/\b(?:O-O-O|O-O|[NBRQK]?[a-h]?[1-8]?x?[a-h][1-8](?:=[NBRQK])?[+#]?)\b/',
                    $pgn,
                    $matches
                );
                return count($matches[0]);
            })($data['pgn']),
        ]);

        return response()->json(['game' => $this->formatGame($game)], 201);
    }

    public function aiLevel(Request $request): JsonResponse
    {
        $user = $request->user();
        $level = \App\Models\UserAiLevel::firstOrCreate(
            ['user_id' => $user->id],
            ['skill_level' => 10, 'elo_estimate' => 1200, 'updated_at' => now()]
        );

        return response()->json([
            'skill_level' => $level->skill_level,
            'elo_estimate' => $level->elo_estimate,
        ]);
    }

    public function updateAiLevel(Request $request): JsonResponse
    {
        $data = $request->validate([
            'result' => 'required|in:win,loss,draw',
            'accuracy' => 'required|numeric|min:0|max:100',
            'skill_level' => 'required|integer|min:0|max:20',
        ]);

        $user = $request->user();
        $level = \App\Models\UserAiLevel::firstOrCreate(
            ['user_id' => $user->id],
            ['skill_level' => $data['skill_level'], 'elo_estimate' => 1200, 'updated_at' => now()]
        );

        $delta = match ($data['result']) {
            'win' => $data['accuracy'] >= 85 ? 1 : 0,
            'loss' => $data['accuracy'] >= 85 ? 0 : -1,
            'draw' => 0,
        };

        $newSkill = max(0, min(20, $level->skill_level + $delta));
        $eloMap = [400, 500, 600, 700, 800, 900, 1000, 1100, 1200, 1300, 1400, 1500, 1600, 1700, 1800, 1900, 2000, 2200, 2400, 2600, 2800];
        $newElo = $eloMap[$newSkill] ?? 1200;

        $level->update(['skill_level' => $newSkill, 'elo_estimate' => $newElo, 'updated_at' => now()]);

        return response()->json(['skill_level' => $newSkill, 'elo_estimate' => $newElo]);
    }

    /**
     * T2.8 — Three-bullet end-of-game synthesis: top theme, biggest missed
     * idea, drill recommendation. T4.13 — Suggests one matching lesson if
     * the drill_motif tag overlaps a lesson theme.
     */
    public function coachSummary(
        Request $request,
        Game $game,
        GeminiCoachService $coach,
        CoachContextService $ctxSvc,
    ): JsonResponse {
        if ($game->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $user = $request->user();

        // Notable moves the LLM should ground on — the actual mistakes worth
        // reviewing, sorted by severity. Cap at 8 so we don't blow the prompt.
        $notable = $game->moveAnalyses()
            ->whereIn('classification', ['blunder', 'mistake', 'miss', 'inaccuracy'])
            ->orderByRaw("CASE classification
                WHEN 'blunder' THEN 1
                WHEN 'miss' THEN 2
                WHEN 'mistake' THEN 3
                ELSE 4 END")
            ->orderByDesc('cp_loss')
            ->limit(8)
            ->get(['move_number', 'color', 'classification', 'move_san', 'best_move_san', 'cp_loss'])
            ->map(fn ($m) => [
                'move_number' => $m->move_number,
                'color' => $m->color,
                'classification' => $m->classification,
                'san' => $m->move_san,
                'best_move_san' => $m->best_move_san,
                'cp_loss' => $m->cp_loss,
            ])
            ->all();

        $ephemeral = $ctxSvc->ephemeralUserContext($user);
        $stable = $ctxSvc->stableUserContext($user);

        $summary = $coach->gameSummary([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'opening_name' => $game->opening_name,
            'user_color' => $game->user_color,
            'user_accuracy' => $game->user_accuracy,
            'result' => $game->result,
            'notable_moves' => $notable,
        ] + $ephemeral + $stable);

        // T4.13 — Pattern-match the LLM's drill_motif tag onto Lesson.theme.
        $lesson = null;
        if (! empty($summary['drill_motif'])) {
            $lesson = Lesson::query()
                ->where('theme', 'like', '%' . str_replace('-', '%', (string) $summary['drill_motif']) . '%')
                ->first();
        }

        // Surface the worst-move location to the client. Drill seeding (which
        // needs a chess engine to compute the FEN at that ply) is done
        // client-side — see games_.$gameId.tsx coachSummary panel.
        $worst = $notable[0] ?? null;

        return response()->json([
            'data' => $summary + [
                'lesson' => $lesson ? [
                    'id' => $lesson->id,
                    'title' => $lesson->title,
                    'theme' => $lesson->theme,
                    'level' => $lesson->level,
                ] : null,
                'worst_move' => $worst,
            ],
        ]);
    }

    private function formatGame(Game $game): array
    {
        $user = request()->user();

        return [
            'id' => $game->id,
            'chess_com_game_id' => $game->chess_com_game_id,
            'white_username' => $game->white_username,
            'black_username' => $game->black_username,
            'white_rating' => $game->white_rating,
            'black_rating' => $game->black_rating,
            'user_color' => $game->user_color,
            'opponent' => $game->user_color === 'white' ? $game->black_username : $game->white_username,
            'opponent_rating' => $game->user_color === 'white' ? $game->black_rating : $game->white_rating,
            'result' => $game->result,
            'time_class' => $game->time_class,
            'time_control' => $game->time_control,
            'opening_name' => $game->opening_name,
            'eco_code' => $game->eco_code,
            'white_accuracy' => $game->white_accuracy !== null ? (float) $game->white_accuracy : null,
            'black_accuracy' => $game->black_accuracy !== null ? (float) $game->black_accuracy : null,
            'user_accuracy' => $game->user_color === 'white'
                ? ($game->white_accuracy !== null ? (float) $game->white_accuracy : null)
                : ($game->black_accuracy !== null ? (float) $game->black_accuracy : null),
            'move_count' => $game->move_count,
            'played_at' => $game->played_at?->toISOString(),
            'analyzed_at' => $game->analyzed_at?->toISOString(),
            'pgn' => $game->pgn,
            'repertoire_deviation_ply' => $game->repertoire_deviation_ply,
            'variant' => $game->variant ?? 'standard',
        ];
    }

    /**
     * Compute the repertoire deviation ply for a game given its PGN moves.
     * Called from SyncController after importing games.
     * Returns the ply index (0-based) of the first deviation, or null if the
     * entire game is within the repertoire (or user has no repertoire).
     */
    public static function computeRepertoireDeviation(int $userId, string $pgn, string $userColor): ?int
    {
        $repertoires = Repertoire::where('user_id', $userId)
            ->where('color', $userColor)
            ->get();

        if ($repertoires->isEmpty()) {
            return null;
        }

        // Parse moves from PGN (simple extraction of SAN moves)
        preg_match_all('/\d+\.\s*(\S+)(?:\s+(\S+))?/', $pgn, $matches);
        $gameMoves = [];
        foreach ($matches[1] as $i => $white) {
            if ($white && $white !== '...' && !str_starts_with($white, '{')) {
                $gameMoves[] = $white;
            }
            $black = $matches[2][$i] ?? '';
            if ($black && $black !== '...' && !str_starts_with($black, '{')) {
                $gameMoves[] = $black;
            }
        }

        $bestDeviationPly = null;

        foreach ($repertoires as $rep) {
            $tree = json_decode($rep->tree_json, true);
            if (!$tree) {
                continue;
            }
            $ply = self::walkTree($tree, $gameMoves, 0);
            if ($bestDeviationPly === null || $ply > $bestDeviationPly) {
                $bestDeviationPly = $ply;
            }
        }

        return $bestDeviationPly;
    }

    private static function walkTree(array $node, array $gameMoves, int $ply): int
    {
        if ($ply >= count($gameMoves)) {
            return $ply;
        }
        $currentMove = $gameMoves[$ply];
        $children = $node['children'] ?? [];
        foreach ($children as $child) {
            $childMove = $child['move'] ?? $child['san'] ?? '';
            if ($childMove === $currentMove) {
                return self::walkTree($child, $gameMoves, $ply + 1);
            }
        }
        // Deviation found at this ply
        return $ply;
    }
}
