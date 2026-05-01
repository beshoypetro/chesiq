<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CoordinateScore;
use App\Models\VisionScore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrainingController extends Controller
{
    // F013 — Board Coordinate Training

    public function coordinateScore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'score' => 'required|integer|min:0',
            'accuracy' => 'required|numeric|min:0|max:100',
        ]);

        $user = $request->user();

        CoordinateScore::create([
            'user_id' => $user->id,
            'score' => $data['score'],
            'accuracy' => $data['accuracy'],
            'created_at' => now(),
        ]);

        $best = CoordinateScore::where('user_id', $user->id)->max('score');

        return response()->json([
            'message' => 'Score saved.',
            'best' => $best,
        ]);
    }

    public function coordinateBest(Request $request): JsonResponse
    {
        $user = $request->user();

        $best = CoordinateScore::where('user_id', $user->id)->max('score');
        $history = CoordinateScore::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['score', 'accuracy', 'created_at'])
            ->map(fn ($s) => [
                'score' => $s->score,
                'accuracy' => $s->accuracy,
                'created_at' => $s->created_at?->toISOString(),
            ]);

        return response()->json([
            'best' => $best,
            'history' => $history,
        ]);
    }

    // F035 — Vision Drills

    public function visionScore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'drill_type' => 'required|string|in:knight_reach,piece_coverage',
            'score' => 'required|integer|min:0',
            'accuracy' => 'required|numeric|min:0|max:100',
        ]);

        $user = $request->user();

        VisionScore::create([
            'user_id' => $user->id,
            'drill_type' => $data['drill_type'],
            'score' => $data['score'],
            'accuracy' => $data['accuracy'],
            'created_at' => now(),
        ]);

        $best = VisionScore::where('user_id', $user->id)
            ->where('drill_type', $data['drill_type'])
            ->max('score');

        return response()->json([
            'message' => 'Score saved.',
            'best' => $best,
        ]);
    }

    public function visionBest(Request $request): JsonResponse
    {
        $user = $request->user();

        $rows = VisionScore::where('user_id', $user->id)
            ->selectRaw('drill_type, MAX(score) as best')
            ->groupBy('drill_type')
            ->get();

        $bests = [];
        foreach ($rows as $row) {
            $bests[$row->drill_type] = $row->best;
        }

        $history = VisionScore::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get(['drill_type', 'score', 'accuracy', 'created_at'])
            ->map(fn ($s) => [
                'drill_type' => $s->drill_type,
                'score' => $s->score,
                'accuracy' => $s->accuracy,
                'created_at' => $s->created_at?->toISOString(),
            ]);

        return response()->json([
            'bests' => $bests,
            'history' => $history,
        ]);
    }
}
