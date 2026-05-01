<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Puzzle;
use App\Models\UserPuzzleAttempt;
use App\Models\UserPuzzleRating;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PuzzleController extends Controller
{
    public function next(Request $request): JsonResponse
    {
        $user = $request->user();
        $theme = $request->input('theme');

        $userRating = UserPuzzleRating::firstOrCreate(
            ['user_id' => $user->id],
            ['rating' => 1500, 'rd' => 350, 'updated_at' => now()]
        );

        // Exclude recently attempted puzzles (last 50)
        $recentIds = UserPuzzleAttempt::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->pluck('puzzle_id');

        $query = Puzzle::whereNotIn('id', $recentIds)
            ->whereBetween('rating', [
                $userRating->rating - 300,
                $userRating->rating + 300,
            ]);

        if ($theme) {
            $query->where('themes', 'like', "%{$theme}%");
        }

        $puzzle = $query->inRandomOrder()->first();

        // Widen search if nothing matched in rating range
        if (!$puzzle) {
            $query2 = Puzzle::whereNotIn('id', $recentIds);
            if ($theme) {
                $query2->where('themes', 'like', "%{$theme}%");
            }
            $puzzle = $query2->inRandomOrder()->first();
        }

        // Last resort: any puzzle
        if (!$puzzle) {
            $puzzle = Puzzle::inRandomOrder()->first();
        }

        if (!$puzzle) {
            return response()->json(['message' => 'No puzzles available. Import the Lichess dataset first.'], 404);
        }

        return response()->json([
            'puzzle' => [
                'id' => $puzzle->id,
                'fen' => $puzzle->fen,
                'moves' => explode(' ', $puzzle->moves),
                'themes' => $puzzle->themes ? explode(' ', $puzzle->themes) : [],
                'rating' => $puzzle->rating,
            ],
            'user_rating' => round($userRating->rating),
        ]);
    }

    public function attempt(Request $request, string $puzzleId): JsonResponse
    {
        $data = $request->validate([
            'solved' => 'required|boolean',
            'time_ms' => 'nullable|integer|min:0',
        ]);

        $user = $request->user();
        $puzzle = Puzzle::findOrFail($puzzleId);

        UserPuzzleAttempt::create([
            'user_id' => $user->id,
            'puzzle_id' => $puzzleId,
            'solved' => $data['solved'],
            'time_ms' => $data['time_ms'] ?? null,
            'created_at' => now(),
        ]);

        $userRating = UserPuzzleRating::firstOrCreate(
            ['user_id' => $user->id],
            ['rating' => 1500, 'rd' => 350, 'updated_at' => now()]
        );

        // ELO-style update (K=20)
        $K = 20;
        $expected = 1 / (1 + pow(10, ($puzzle->rating - $userRating->rating) / 400));
        $result = $data['solved'] ? 1.0 : 0.0;
        $userDelta = $K * ($result - $expected);
        $puzzleDelta = $K * ((1 - $result) - (1 - $expected));

        $newUserRating = max(400, min(3000, $userRating->rating + $userDelta));
        $newPuzzleRating = max(400, min(3000, $puzzle->rating + $puzzleDelta));

        $userRating->update(['rating' => $newUserRating, 'updated_at' => now()]);
        $puzzle->update(['rating' => $newPuzzleRating]);

        return response()->json([
            'solved' => $data['solved'],
            'rating_change' => (int) round($userDelta),
            'new_rating' => (int) round($newUserRating),
            'puzzle_rating' => (int) round($newPuzzleRating),
        ]);
    }

    public function themes(): JsonResponse
    {
        return response()->json([
            'themes' => [
                'fork', 'pin', 'skewer', 'discoveredAttack', 'backRankMate',
                'sacrifice', 'deflection', 'overloading', 'interference',
                'zugzwang', 'mateIn1', 'mateIn2', 'mateIn3', 'endgame',
                'opening', 'middlegame', 'crushing', 'advantage', 'promotion',
                'trappedPiece',
            ],
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $user = $request->user();

        $attempts = UserPuzzleAttempt::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get(['puzzle_id', 'solved', 'time_ms', 'created_at']);

        $userRating = UserPuzzleRating::where('user_id', $user->id)->first();

        return response()->json([
            'current_rating' => $userRating ? (int) round($userRating->rating) : 1500,
            'attempts' => $attempts->map(fn ($a) => [
                'puzzle_id' => $a->puzzle_id,
                'solved' => (bool) $a->solved,
                'time_ms' => $a->time_ms,
                'date' => $a->created_at,
            ]),
            'stats' => [
                'total' => $attempts->count(),
                'solved' => $attempts->where('solved', true)->count(),
                'solve_rate' => $attempts->count() > 0
                    ? (int) round($attempts->where('solved', true)->count() / $attempts->count() * 100)
                    : 0,
            ],
        ]);
    }
}
