<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncController extends Controller
{
    public function sync(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->chess_com_username) {
            return response()->json(['message' => 'No chess.com username linked.'], 422);
        }

        $username = $user->chess_com_username;

        // Get list of monthly archive URLs
        $archivesJson = $this->fetchUrl("https://api.chess.com/pub/player/{$username}/games/archives");
        if (!$archivesJson) {
            return response()->json(['message' => 'Could not reach chess.com API.'], 502);
        }

        $archives = json_decode($archivesJson, true)['archives'] ?? [];
        if (empty($archives)) {
            return response()->json(['new_games' => 0, 'message' => 'No games found.']);
        }

        // Fetch most recent 2 months to catch new games
        $recentArchives = array_slice($archives, -2);
        $newCount = 0;

        foreach ($recentArchives as $archiveUrl) {
            $gamesJson = $this->fetchUrl($archiveUrl);
            if (!$gamesJson) continue;

            $games = json_decode($gamesJson, true)['games'] ?? [];

            foreach ($games as $g) {
                $gameId = basename($g['url'] ?? '');
                if (!$gameId || Game::where('chess_com_game_id', $gameId)->exists()) {
                    continue;
                }

                $userColor = strtolower($g['white']['username'] ?? '') === $username ? 'white' : 'black';
                $whiteResult = $g['white']['result'] ?? '';
                $result = match (true) {
                    $userColor === 'white' && $whiteResult === 'win' => 'win',
                    $userColor === 'black' && $whiteResult !== 'win' && in_array($g['black']['result'] ?? '', ['win']) => 'win',
                    in_array($whiteResult, ['agreed', 'repetition', 'stalemate', 'insufficient', '50move', 'timevsinsufficient']) => 'draw',
                    default => 'loss',
                };

                // Parse opening from PGN headers
                $pgn = $g['pgn'] ?? '';
                $openingName = $this->extractPgnHeader($pgn, 'ECOUrl');
                if ($openingName) {
                    $openingName = basename(str_replace('-', ' ', $openingName));
                    $openingName = ucwords($openingName);
                }

                Game::create([
                    'user_id'           => $user->id,
                    'chess_com_game_id' => $gameId,
                    'pgn'               => $pgn,
                    'white_username'    => $g['white']['username'] ?? '',
                    'black_username'    => $g['black']['username'] ?? '',
                    'white_rating'      => $g['white']['rating'] ?? null,
                    'black_rating'      => $g['black']['rating'] ?? null,
                    'user_color'        => $userColor,
                    'result'            => $result,
                    'time_class'        => $g['time_class'] ?? null,
                    'time_control'      => $g['time_control'] ?? null,
                    'opening_name'      => $openingName,
                    'eco_code'          => $this->extractPgnHeader($pgn, 'ECO'),
                    'move_count'        => $this->countMoves($pgn),
                    'played_at'         => isset($g['end_time']) ? date('Y-m-d H:i:s', $g['end_time']) : null,
                ]);

                $newCount++;
            }
        }

        $user->update(['last_synced_at' => now()]);

        return response()->json([
            'new_games' => $newCount,
            'message'   => $newCount > 0 ? "{$newCount} new game(s) synced." : 'Already up to date.',
        ]);
    }

    private function fetchUrl(string $url): string|false
    {
        try {
            $resp = Http::withHeaders(['User-Agent' => 'Chesiq/1.0'])->timeout(10)->get($url);
            if (!$resp->successful()) {
                Log::warning('chess.com sync fetch non-success', ['url' => $url, 'status' => $resp->status()]);
                return false;
            }
            return $resp->body();
        } catch (\Throwable $e) {
            Log::warning('chess.com sync fetch failed', ['url' => $url, 'error' => $e->getMessage()]);
            return false;
        }
    }

    private function extractPgnHeader(string $pgn, string $key): ?string
    {
        if (preg_match('/\[' . preg_quote($key, '/') . '\s+"([^"]+)"\]/', $pgn, $m)) {
            return $m[1];
        }
        return null;
    }

    private function countMoves(string $pgn): int
    {
        preg_match_all('/\d+\.(?!\.)/', $pgn, $m);
        return count($m[0]);
    }
}
