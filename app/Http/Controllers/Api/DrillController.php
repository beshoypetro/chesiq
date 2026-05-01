<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DrillPosition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DrillController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $drills = DrillPosition::where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'drills' => $drills->map(fn ($d) => $this->format($d)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:100',
            'fen' => 'required|string',
            'color' => 'required|in:white,black',
        ]);

        $drill = DrillPosition::create([
            'user_id' => $request->user()->id,
            'name' => $data['name'] ?? null,
            'fen' => $data['fen'],
            'color' => $data['color'],
        ]);

        return response()->json(['drill' => $this->format($drill)], 201);
    }

    public function attempt(Request $request, int $id): JsonResponse
    {
        $drill = DrillPosition::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $data = $request->validate([
            'result' => 'required|in:win,draw,loss',
        ]);

        $drill->increment('attempts');
        $drill->increment($data['result'] . 's');

        return response()->json(['drill' => $this->format($drill->fresh())]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $drill = DrillPosition::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $drill->delete();

        return response()->json(['message' => 'Drill position deleted.']);
    }

    private function format(DrillPosition $d): array
    {
        return [
            'id' => $d->id,
            'name' => $d->name,
            'fen' => $d->fen,
            'color' => $d->color,
            'attempts' => $d->attempts,
            'wins' => $d->wins,
            'draws' => $d->draws,
            'losses' => $d->losses,
        ];
    }
}
