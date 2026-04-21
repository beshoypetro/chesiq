<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\MoveAnalysis;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function dashboard(): JsonResponse
    {
        $totalUsers    = User::count();
        $totalGames    = Game::count();
        $analyzedGames = Game::whereNotNull('analyzed_at')->count();
        $avgAccuracy   = Game::whereNotNull('white_accuracy')
            ->selectRaw('AVG((white_accuracy + black_accuracy) / 2) as avg')
            ->value('avg');

        $gamesByTimeClass = Game::selectRaw('time_class, COUNT(*) as count')
            ->groupBy('time_class')
            ->whereNotNull('time_class')
            ->pluck('count', 'time_class');

        $recentUsers = User::latest()->take(10)->get()->map(fn($u) => [
            'id'                 => $u->id,
            'name'               => $u->name,
            'email'              => $u->email,
            'is_admin'           => (bool) $u->is_admin,
            'chess_com_username' => $u->chess_com_username,
            'games_count'        => $u->games()->count(),
            'analyzed_count'     => $u->games()->whereNotNull('analyzed_at')->count(),
            'created_at'         => $u->created_at?->toISOString(),
            'last_synced_at'     => $u->last_synced_at?->toISOString(),
        ]);

        $recentGames = Game::with('user')
            ->latest('played_at')
            ->take(10)
            ->get()
            ->map(fn($g) => [
                'id'            => $g->id,
                'user_name'     => $g->user?->name,
                'white'         => $g->white_username,
                'black'         => $g->black_username,
                'result'        => $g->result,
                'time_class'    => $g->time_class,
                'analyzed'      => !!$g->analyzed_at,
                'user_accuracy' => $g->user_color === 'white' ? $g->white_accuracy : $g->black_accuracy,
                'played_at'     => $g->played_at?->toISOString(),
            ]);

        $moveStats = MoveAnalysis::selectRaw('classification, COUNT(*) as count')
            ->groupBy('classification')
            ->whereNotNull('classification')
            ->pluck('count', 'classification');

        return response()->json([
            'data' => [
                'total_users'        => $totalUsers,
                'total_games'        => $totalGames,
                'analyzed_games'     => $analyzedGames,
                'avg_accuracy'       => $avgAccuracy ? round($avgAccuracy, 1) : null,
                'games_by_time'      => $gamesByTimeClass,
                'move_stats'         => $moveStats,
                'recent_users'       => $recentUsers,
                'recent_games'       => $recentGames,
            ],
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $query = User::withCount('games');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")->orWhere('chess_com_username', 'like', "%{$s}%"));
        }

        $users = $query->latest()->paginate(20);

        return response()->json([
            'data' => $users->map(fn($u) => [
                'id'                 => $u->id,
                'name'               => $u->name,
                'email'              => $u->email,
                'is_admin'           => (bool) $u->is_admin,
                'chess_com_username' => $u->chess_com_username,
                'games_count'        => $u->games_count,
                'created_at'         => $u->created_at?->toISOString(),
                'last_synced_at'     => $u->last_synced_at?->toISOString(),
            ]),
            'meta' => [
                'total'        => $users->total(),
                'current_page' => $users->currentPage(),
                'last_page'    => $users->lastPage(),
            ],
        ]);
    }

    public function toggleAdmin(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'Cannot change your own admin status.'], 422);
        }

        $user->update(['is_admin' => !$user->is_admin]);

        return response()->json([
            'message'  => $user->is_admin ? 'User promoted to admin.' : 'Admin rights revoked.',
            'is_admin' => $user->is_admin,
        ]);
    }

    public function deleteUser(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'Cannot delete your own account here.'], 422);
        }

        $user->delete();
        return response()->json(['message' => 'User deleted.']);
    }
}
