<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ExplorerController extends Controller
{
    /**
     * GET /api/chess/explorer — proxy to Lichess Opening Explorer.
     * Supports both masters (OTB) and lichess databases.
     */
    public function index(Request $request): JsonResponse
    {
        $fen  = $request->query('fen');
        $play = $request->query('play');
        $db   = $request->query('db', 'masters'); // 'masters' or 'lichess'

        if (! $fen) {
            return response()->json(['error' => 'FEN required'], 422);
        }

        $endpoint = $db === 'lichess'
            ? 'https://explorer.lichess.ovh/lichess'
            : 'https://explorer.lichess.ovh/masters';

        $params = ['fen' => $fen];
        if ($play) {
            $params['play'] = $play;
        }

        try {
            $response = Http::timeout(8)->get($endpoint, $params);
        } catch (\Throwable) {
            return response()->json(['error' => 'Explorer unavailable'], 502);
        }

        if (! $response->successful()) {
            return response()->json(['error' => 'Explorer request failed'], 502);
        }

        return response()->json($response->json());
    }
}
