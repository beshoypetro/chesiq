<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Puzzle;
use App\Models\PuzzleSet;
use App\Models\UserPuzzleAttempt;
use App\Models\UserPuzzleRating;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PuzzleSetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $sets = PuzzleSet::where('user_id', $user->id)->get();

        // Ensure the "My Mistakes" auto-set exists
        $autoSet = $sets->firstWhere('is_auto', true);
        if (!$autoSet) {
            $autoSet = PuzzleSet::create([
                'user_id' => $user->id,
                'name' => 'My Mistakes',
                'is_auto' => true,
                'share_token' => null,
            ]);
            $this->syncAutoSet($autoSet, $user->id);
            $sets = $sets->push($autoSet);
        }

        return response()->json([
            'sets' => $sets->map(fn ($s) => $this->format($s)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $set = PuzzleSet::create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'is_auto' => false,
            'share_token' => Str::random(16),
        ]);

        return response()->json(['set' => $this->format($set)], 201);
    }

    public function next(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $set = PuzzleSet::where('id', $id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        // Sync auto set before serving
        if ($set->is_auto) {
            $this->syncAutoSet($set, $user->id);
        }

        $userRating = UserPuzzleRating::firstOrCreate(
            ['user_id' => $user->id],
            ['rating' => 1500, 'rd' => 350, 'updated_at' => now()]
        );

        // Get a puzzle from the set not recently attempted
        $recentIds = UserPuzzleAttempt::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->pluck('puzzle_id');

        $puzzle = $set->puzzles()
            ->whereNotIn('puzzles.id', $recentIds)
            ->inRandomOrder()
            ->first();

        if (!$puzzle) {
            $puzzle = $set->puzzles()->inRandomOrder()->first();
        }

        if (!$puzzle) {
            return response()->json(['message' => 'No puzzles in this set.'], 404);
        }

        return response()->json([
            'puzzle' => [
                'id' => $puzzle->id,
                'fen' => $puzzle->fen,
                'moves' => explode(' ', $puzzle->moves),
                'themes' => $puzzle->themes ? explode(' ', $puzzle->themes) : [],
                'rating' => $puzzle->rating,
            ],
            'user_rating' => (int) round($userRating->rating),
        ]);
    }

    public function addPuzzle(Request $request, int $id, string $puzzleId): JsonResponse
    {
        $set = PuzzleSet::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if ($set->is_auto) {
            return response()->json(['message' => 'Cannot manually add to auto-set.'], 422);
        }

        Puzzle::findOrFail($puzzleId);
        $set->puzzles()->syncWithoutDetaching([$puzzleId]);

        return response()->json(['message' => 'Puzzle added to set.']);
    }

    private function syncAutoSet(PuzzleSet $set, int $userId): void
    {
        // Populate with puzzles the user got wrong in the last 30 days
        $wrongIds = UserPuzzleAttempt::where('user_id', $userId)
            ->where('solved', false)
            ->where('created_at', '>=', now()->subDays(30))
            ->pluck('puzzle_id')
            ->unique()
            ->values();

        if ($wrongIds->isNotEmpty()) {
            $set->puzzles()->syncWithoutDetaching($wrongIds->toArray());
        }
    }

    private function format(PuzzleSet $s): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'is_auto' => $s->is_auto,
            'puzzle_count' => $s->puzzles()->count(),
        ];
    }
}
