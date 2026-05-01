<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class DatabaseController extends Controller
{
    /**
     * GET /api/database/games — proxy Lichess master game API.
     * Supports search by FEN position, player name, result, ECO.
     */
    public function games(Request $request): JsonResponse
    {
        $fen    = $request->query('fen');
        $player = $request->query('player');
        $since  = $request->query('since');
        $until  = $request->query('until');
        $limit  = min(30, (int) $request->query('limit', 10));

        // If FEN provided, use the position search endpoint
        if ($fen) {
            $params = ['fen' => $fen, 'topGames' => $limit];
            try {
                $response = Http::timeout(8)->get('https://explorer.lichess.ovh/masters', $params);
            } catch (\Throwable) {
                return response()->json(['error' => 'Database unavailable'], 502);
            }

            if (! $response->successful()) {
                return response()->json(['error' => 'Database request failed'], 502);
            }

            $data = $response->json();
            return response()->json([
                'games' => $data['topGames'] ?? [],
                'moves' => $data['moves'] ?? [],
                'white' => $data['white'] ?? 0,
                'draws' => $data['draws'] ?? 0,
                'black' => $data['black'] ?? 0,
            ]);
        }

        // Fallback: player name search via Lichess games API
        if ($player) {
            $params = [
                'rated' => 'true',
                'perfType' => 'classical',
                'max' => $limit,
            ];
            if ($since) {
                $params['since'] = strtotime($since) * 1000;
            }
            if ($until) {
                $params['until'] = strtotime($until) * 1000;
            }

            try {
                $response = Http::timeout(10)
                    ->withHeaders(['Accept' => 'application/x-ndjson'])
                    ->get("https://lichess.org/api/games/user/{$player}", $params);
            } catch (\Throwable) {
                return response()->json(['error' => 'Database unavailable'], 502);
            }

            if (! $response->successful()) {
                return response()->json(['error' => 'Player not found or database error'], 502);
            }

            $lines = array_filter(explode("\n", $response->body()));
            $games = array_values(array_map(fn ($l) => json_decode($l, true), $lines));

            return response()->json(['games' => array_filter($games)]);
        }

        return response()->json(['error' => 'Provide fen or player parameter'], 422);
    }
}
