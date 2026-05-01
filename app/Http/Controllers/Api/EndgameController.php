<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EndgamePosition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class EndgameController extends Controller
{
    /**
     * GET /api/endgame/next — return next position for the user's rating in given category.
     */
    public function next(Request $request): JsonResponse
    {
        $user = $request->user();
        $category = $request->query('category');

        $query = EndgamePosition::query();
        if ($category) {
            $query->where('category', $category);
        }

        // Get user rating for category
        $userRating = 1200;
        if ($category) {
            $row = DB::table('user_endgame_ratings')
                ->where('user_id', $user->id)
                ->where('category', $category)
                ->first();
            $userRating = $row ? $row->rating : 1200;
        }

        // Pick a position close to user's rating
        $position = $query->orderByRaw('ABS(difficulty - ?)', [$userRating])
            ->inRandomOrder()
            ->limit(5)
            ->get()
            ->random();

        if (! $position) {
            return response()->json(['message' => 'No positions found.'], 404);
        }

        return response()->json([
            'position' => [
                'id' => $position->id,
                'fen' => $position->fen,
                'category' => $position->category,
                'description' => $position->description,
                'difficulty' => $position->difficulty,
            ],
            'user_rating' => round($userRating),
        ]);
    }

    /**
     * POST /api/endgame/{id}/attempt — record attempt and update rating.
     */
    public function attempt(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'correct' => 'required|boolean',
        ]);

        $position = EndgamePosition::findOrFail($id);

        // Record attempt
        DB::table('user_endgame_attempts')->insert([
            'user_id' => $user->id,
            'endgame_position_id' => $position->id,
            'correct' => $data['correct'],
            'created_at' => now(),
        ]);

        // Update user's rating for this category (simple Elo-like)
        $row = DB::table('user_endgame_ratings')
            ->where('user_id', $user->id)
            ->where('category', $position->category)
            ->first();

        $currentRating = $row ? $row->rating : 1200;
        $K = 32;
        $expected = 1 / (1 + pow(10, ($position->difficulty - $currentRating) / 400));
        $actual = $data['correct'] ? 1.0 : 0.0;
        $newRating = round($currentRating + $K * ($actual - $expected));

        DB::table('user_endgame_ratings')->updateOrInsert(
            ['user_id' => $user->id, 'category' => $position->category],
            ['rating' => $newRating]
        );

        return response()->json([
            'correct' => $data['correct'],
            'rating_change' => $newRating - round($currentRating),
            'new_rating' => $newRating,
            'position_difficulty' => $position->difficulty,
        ]);
    }

    /**
     * GET /api/endgame/tablebase?fen=… — proxy Lichess tablebase API.
     */
    public function tablebase(Request $request): JsonResponse
    {
        $fen = $request->query('fen');
        if (! $fen) {
            return response()->json(['error' => 'FEN required'], 422);
        }

        $response = Http::get('https://tablebase.lichess.ovh/standard', ['fen' => $fen]);

        if (! $response->successful()) {
            return response()->json(['error' => 'Tablebase lookup failed'], 502);
        }

        return response()->json($response->json());
    }

    /**
     * GET /api/endgame/ratings — return user's ratings per category.
     */
    public function ratings(Request $request): JsonResponse
    {
        $user = $request->user();

        $rows = DB::table('user_endgame_ratings')
            ->where('user_id', $user->id)
            ->get(['category', 'rating']);

        $categories = ['pawn', 'rook', 'queen', 'bishop', 'knight', 'king'];
        $result = [];
        foreach ($categories as $cat) {
            $found = $rows->firstWhere('category', $cat);
            $result[$cat] = $found ? round($found->rating) : null;
        }

        return response()->json(['ratings' => $result]);
    }
}
