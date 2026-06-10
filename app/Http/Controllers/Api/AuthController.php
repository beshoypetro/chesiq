<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'chess_com_username' => 'nullable|string|max:50',
        ]);

        // Validate chess.com username if provided
        $chessCom = null;
        if (! empty($data['chess_com_username'])) {
            $username = strtolower(trim($data['chess_com_username']));
            if (! $this->chessComUsernameExists($username)) {
                return response()->json([
                    'message' => 'chess.com username not found.',
                    'errors' => ['chess_com_username' => ['Username not found on chess.com.']],
                ], 422);
            }
            $chessCom = $username;
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'chess_com_username' => $chessCom,
        ]);
        $token = $user->createToken('chesiq')->plainTextToken;

        // Auto-sync most recent month of games on registration
        $newGames = $chessCom ? $this->syncGamesForUser($user, $chessCom) : 0;

        return response()->json([
            'user' => $this->formatUser($user),
            'token' => $token,
            'new_games' => $newGames,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required']);

        if (! Auth::attempt($data)) {
            throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
        }

        $user = Auth::user();
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
        $data = $request->validate(['chess_com_username' => 'required|string|max:50']);
        $username = strtolower(trim($data['chess_com_username']));

        if (! $this->chessComUsernameExists($username)) {
            return response()->json(['message' => 'chess.com username not found.'], 422);
        }

        $request->user()->update(['chess_com_username' => $username]);

        return response()->json(['user' => $this->formatUser($request->user()->fresh())]);
    }

    // ── V2 onboarding endpoints (spec §22) ───────────────────────────────────

    public function updateIntent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'intents' => 'required|array|min:1|max:5',
            'intents.*' => 'string|in:build_openings,stop_losing_endgames,sharpen_tactics,take_lessons,just_play_more',
        ]);

        $request->user()->update(['intents' => $data['intents']]);

        return response()->json(['user' => $this->formatUser($request->user()->fresh())]);
    }

    public function updateCoachingMode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'coaching_mode' => 'required|string|in:full,tour,off',
        ]);

        $request->user()->update(['coaching_mode' => $data['coaching_mode']]);

        return response()->json(['user' => $this->formatUser($request->user()->fresh())]);
    }

    /**
     * Update Settings-page preferences. Any subset of the three toggles may be
     * sent. `email_notifications` maps onto the existing digest opt-out column
     * so the weekly-digest recipient query keeps working unchanged; the other
     * two persist in the `preferences` JSON column.
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email_notifications' => 'sometimes|boolean',
            'auto_analyze' => 'sometimes|boolean',
            'public_profile' => 'sometimes|boolean',
        ]);

        $user = $request->user();

        if (array_key_exists('email_notifications', $data)) {
            $user->digest_unsubscribed_at = $data['email_notifications'] ? null : now();
        }

        $stored = is_array($user->preferences) ? $user->preferences : [];
        foreach (['auto_analyze', 'public_profile'] as $key) {
            if (array_key_exists($key, $data)) {
                $stored[$key] = $data[$key];
            }
        }
        $user->preferences = $stored;
        $user->save();

        return response()->json(['user' => $this->formatUser($user->fresh())]);
    }

    public function onboardingStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'onboarding_step' => (int) ($user->onboarding_step ?? 0),
            'onboarding_completed_at' => $user->onboarding_completed_at?->toISOString(),
            'coaching_mode' => $user->coaching_mode ?? 'full',
            'selected_trainer_id' => $user->selected_trainer_id,
            'has_chess_com' => ! empty($user->chess_com_username),
            'has_lichess' => ! empty($user->lichess_username),
            'has_intents' => is_array($user->intents) && count($user->intents) > 0,
        ]);
    }

    public function onboardingSkip(Request $request): JsonResponse
    {
        $data = $request->validate([
            'step' => 'required|integer|min:0|max:6',
        ]);

        $user = $request->user();
        $current = (int) ($user->onboarding_step ?? 0);
        $next = max($current, (int) $data['step']);

        $update = ['onboarding_step' => $next];
        if ($next >= 6) {
            $update['onboarding_completed_at'] = now();
        }
        $user->update($update);

        return response()->json([
            'onboarding_step' => $next,
            'onboarding_completed_at' => $user->fresh()->onboarding_completed_at?->toISOString(),
        ]);
    }

    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_admin' => (bool) $user->is_admin,
            'chess_com_username' => $user->chess_com_username,
            'last_synced_at' => $user->last_synced_at?->toISOString(),
            // V2 fields
            'lichess_username' => $user->lichess_username,
            'selected_trainer_id' => $user->selected_trainer_id,
            'coaching_mode' => $user->coaching_mode ?? 'full',
            'onboarding_step' => (int) ($user->onboarding_step ?? 0),
            'onboarding_completed_at' => $user->onboarding_completed_at?->toISOString(),
            'intents' => is_array($user->intents) ? $user->intents : [],
            'preferences' => $user->resolvedPreferences(),
        ];
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function syncGamesForUser(User $user, string $username): int
    {
        $archivesJson = $this->fetchUrl("https://api.chess.com/pub/player/{$username}/games/archives");
        if (! $archivesJson) {
            return 0;
        }

        $archives = json_decode($archivesJson, true)['archives'] ?? [];
        if (empty($archives)) {
            return 0;
        }

        $newCount = 0;
        foreach (array_slice($archives, -1) as $archiveUrl) {
            $gamesJson = $this->fetchUrl($archiveUrl);
            if (! $gamesJson) {
                continue;
            }

            foreach ((json_decode($gamesJson, true)['games'] ?? []) as $g) {
                $gameId = basename($g['url'] ?? '');
                if (! $gameId || Game::where('chess_com_game_id', $gameId)->exists()) {
                    continue;
                }

                $userColor = strtolower($g['white']['username'] ?? '') === $username ? 'white' : 'black';
                $whiteResult = $g['white']['result'] ?? '';
                $result = match (true) {
                    $userColor === 'white' && $whiteResult === 'win' => 'win',
                    $userColor === 'black' && in_array($g['black']['result'] ?? '', ['win']) => 'win',
                    in_array($whiteResult, ['agreed', 'repetition', 'stalemate', 'insufficient', '50move', 'timevsinsufficient']) => 'draw',
                    default => 'loss',
                };

                $pgn = $g['pgn'] ?? '';
                $openingName = $this->extractPgnHeader($pgn, 'ECOUrl');
                if ($openingName) {
                    $openingName = ucwords(basename(str_replace('-', ' ', $openingName)));
                }

                Game::create([
                    'user_id' => $user->id,
                    'chess_com_game_id' => $gameId,
                    'pgn' => $pgn,
                    'white_username' => $g['white']['username'] ?? '',
                    'black_username' => $g['black']['username'] ?? '',
                    'white_rating' => $g['white']['rating'] ?? null,
                    'black_rating' => $g['black']['rating'] ?? null,
                    'user_color' => $userColor,
                    'result' => $result,
                    'time_class' => $g['time_class'] ?? null,
                    'time_control' => $g['time_control'] ?? null,
                    'opening_name' => $openingName,
                    'eco_code' => $this->extractPgnHeader($pgn, 'ECO'),
                    'move_count' => $this->countMoves($pgn),
                    'played_at' => isset($g['end_time']) ? date('Y-m-d H:i:s', $g['end_time']) : null,
                ]);
                $newCount++;
            }
        }

        if ($newCount > 0) {
            $user->update(['last_synced_at' => now()]);
        }

        return $newCount;
    }

    private function fetchUrl(string $url): string|false
    {
        try {
            $resp = Http::withHeaders(['User-Agent' => 'Chesiq/1.0'])->timeout(10)->get($url);
            if (! $resp->successful()) {
                Log::warning('chess.com fetch non-success', ['url' => $url, 'status' => $resp->status()]);

                return false;
            }

            return $resp->body();
        } catch (\Throwable $e) {
            Log::warning('chess.com fetch failed', ['url' => $url, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function chessComUsernameExists(string $username): bool
    {
        // Cache positive and negative results for 1 hour to limit how quickly an
        // attacker can enumerate chess.com usernames through our endpoints.
        return Cache::remember(
            'chesscom:exists:'.strtolower($username),
            now()->addHour(),
            function () use ($username): bool {
                try {
                    $resp = Http::withHeaders(['User-Agent' => 'Chesiq/1.0'])->timeout(5)
                        ->get("https://api.chess.com/pub/player/{$username}");

                    return $resp->successful() && is_array($resp->json());
                } catch (\Throwable $e) {
                    Log::warning('chess.com username lookup failed', ['username' => $username, 'error' => $e->getMessage()]);

                    return false;
                }
            }
        );
    }

    private function extractPgnHeader(string $pgn, string $key): ?string
    {
        if (preg_match('/\['.preg_quote($key, '/').'\s+"([^"]+)"\]/', $pgn, $m)) {
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
