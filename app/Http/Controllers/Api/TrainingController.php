<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TrainingController extends Controller
{
    public function coordinateScore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'score' => 'required|integer|min:0',
            'accuracy' => 'nullable|numeric|min:0|max:100',
        ]);

        $user = $request->user();

        DB::table('coordinate_scores')->insert([
            'user_id' => $user->id,
            'score' => $data['score'],
            'accuracy' => $data['accuracy'] ?? null,
            'created_at' => now(),
        ]);

        $best = DB::table('coordinate_scores')
            ->where('user_id', $user->id)
            ->max('score') ?? 0;

        return response()->json(['message' => 'Score saved.', 'best' => $best]);
    }

    public function coordinateBest(Request $request): JsonResponse
    {
        $user = $request->user();

        $best = DB::table('coordinate_scores')
            ->where('user_id', $user->id)
            ->max('score');

        $history = DB::table('coordinate_scores')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['score', 'accuracy', 'created_at']);

        return response()->json([
            'best' => $best,
            'history' => $history,
        ]);
    }

    public function visionScore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'drill_type' => 'required|string|max:50',
            'score' => 'required|integer|min:0',
            'accuracy' => 'nullable|numeric|min:0|max:100',
        ]);

        $user = $request->user();

        DB::table('vision_scores')->insert([
            'user_id' => $user->id,
            'drill_type' => $data['drill_type'],
            'score' => $data['score'],
            'accuracy' => $data['accuracy'] ?? null,
            'created_at' => now(),
        ]);

        $best = DB::table('vision_scores')
            ->where('user_id', $user->id)
            ->where('drill_type', $data['drill_type'])
            ->max('score') ?? 0;

        return response()->json(['message' => 'Score saved.', 'best' => $best]);
    }

    public function visionBest(Request $request): JsonResponse
    {
        $user = $request->user();

        $bests = DB::table('vision_scores')
            ->where('user_id', $user->id)
            ->selectRaw('drill_type, MAX(score) as best')
            ->groupBy('drill_type')
            ->pluck('best', 'drill_type');

        $history = DB::table('vision_scores')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['drill_type', 'score', 'accuracy', 'created_at']);

        return response()->json([
            'bests' => $bests,
            'history' => $history,
        ]);
    }
}
