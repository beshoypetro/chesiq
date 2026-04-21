<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'               => 'required|string|max:255',
            'email'              => 'required|string|email|max:255|unique:users',
            'password'           => 'required|string|min:8|confirmed',
            'chess_com_username' => 'nullable|string|max:50',
        ]);

        // Validate chess.com username if provided
        $chessCom = null;
        if (!empty($data['chess_com_username'])) {
            $username = strtolower(trim($data['chess_com_username']));
            $ctx  = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5, 'header' => "User-Agent: Chesiq/1.0\r\n"]]);
            $resp = @file_get_contents("https://api.chess.com/pub/player/{$username}", false, $ctx);
            if ($resp === false || !json_decode($resp)) {
                return response()->json([
                    'message' => 'chess.com username not found.',
                    'errors'  => ['chess_com_username' => ['Username not found on chess.com.']],
                ], 422);
            }
            $chessCom = $username;
        }

        $user  = User::create([
            'name'               => $data['name'],
            'email'              => $data['email'],
            'password'           => Hash::make($data['password']),
            'chess_com_username' => $chessCom,
        ]);
        $token = $user->createToken('chesiq')->plainTextToken;

        // Auto-sync most recent month of games on registration
        $newGames = $chessCom ? $this->syncGamesForUser($user, $chessCom) : 0;

        return response()->json([
            'user'      => $this->formatUser($user),
            'token'     => $token,
            'new_games' => $newGames,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required']);

        if (!Auth::attempt($data)) {
            throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
        }

        $user  = Auth::user();
        $token = $user->createToken('chesiq')->plainTextToken;

        return response()->json(['user' => $this->formatUser($user), 'token' => $token]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->formatUser($request->user())]);
    }

    public function updateUsername(Request $request): JsonResponse
    {
        $data     = $request->validate(['chess_com_username' => 'required|string|max:50']);
        $username = strtolower(trim($data['chess_com_username']));

        $ctx  = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5, 'header' => "User-Agent: Chesiq/1.0\r\n"]]);
        $resp = @file_get_contents("https://api.chess.com/pub/player/{$username}", false, $ctx);
        if ($resp === false || !json_decode($resp)) {
            return response()->json(['message' => 'chess.com username not found.'], 422);
        }

        $request->user()->update(['chess_com_username' => $username]);
        return response()->json(['user' => $this->formatUser($request->user()->fresh())]);
    }

    private function formatUser(User $user): array
    {
        return [
            'id'                 => $user->id,
            'name'               => $user->name,
            'email'              => $user->email,
            'is_admin'           => (bool) $user->is_admin,
            'chess_com_username' => $user->chess_com_username,
            'last_synced_at'     => $user->last_synced_at?->toISOString(),
        ];
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function syncGamesForUser(User $user, string $username): int
    {
        $archivesJson = $this->fetchUrl("https://api.chess.com/pub/player/{$username}/games/archives");
        if (!$archivesJson) return 0;

        $archives = json_decode($archivesJson, true)['archives'] ?? [];
        if (empty($archives)) return 0;

        $newCount = 0;
        foreach (array_slice($archives, -1) as $archiveUrl) {
            $gamesJson = $this->fetchUrl($archiveUrl);
            if (!$gamesJson) continue;

            foreach ((json_decode($gamesJson, true)['games'] ?? []) as $g) {
                $gameId = basename($g['url'] ?? '');
                if (!$gameId || Game::where('chess_com_game_id', $gameId)->exists()) continue;

                $userColor   = strtolower($g['white']['username'] ?? '') === $username ? 'white' : 'black';
                $whiteResult = $g['white']['result'] ?? '';
                $result      = match (true) {
                    $userColor === 'white' && $whiteResult === 'win'                                                              => 'win',
                    $userColor === 'black' && in_array($g['black']['result'] ?? '', ['win'])                                     => 'win',
                    in_array($whiteResult, ['agreed', 'repetition', 'stalemate', 'insufficient', '50move', 'timevsinsufficient']) => 'draw',
                    default                                                                                                       => 'loss',
                };

                $pgn         = $g['pgn'] ?? '';
                $openingName = $this->extractPgnHeader($pgn, 'ECOUrl');
                if ($openingName) $openingName = ucwords(basename(str_replace('-', ' ', $openingName)));

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

        if ($newCount > 0) $user->update(['last_synced_at' => now()]);
        return $newCount;
    }

    private function fetchUrl(string $url): string|false
    {
        $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true, 'header' => "User-Agent: Chesiq/1.0\r\n"]]);
        return @file_get_contents($url, false, $ctx);
    }

    private function extractPgnHeader(string $pgn, string $key): ?string
    {
        if (preg_match('/\[' . preg_quote($key, '/') . '\s+"([^"]+)"\]/', $pgn, $m)) return $m[1];
        return null;
    }

    private function countMoves(string $pgn): int
    {
        preg_match_all('/\d+\.(?!\.)/', $pgn, $m);
        return count($m[0]);
    }
}
