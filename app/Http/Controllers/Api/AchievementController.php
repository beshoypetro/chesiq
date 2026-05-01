<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AchievementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AchievementController extends Controller
{
    public function __construct(private AchievementService $service) {}

    /**
     * GET /api/achievements — all achievement definitions.
     */
    public function index(): JsonResponse
    {
        $achievements = DB::table('achievements')->get();
        return response()->json(['achievements' => $achievements]);
    }

    /**
     * GET /api/achievements/user — user's earned achievements + streak.
     */
    public function user(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->service->updateStreak($user);

        // check() already calls seed() internally — no need to call it explicitly here
        $earned = DB::table('user_achievements as ua')
            ->join('achievements as a', 'ua.achievement_id', '=', 'a.id')
            ->where('ua.user_id', $user->id)
            ->select('a.*', 'ua.earned_at')
            ->get();

        $newlyEarned = $this->service->check($user->fresh());

        return response()->json([
            'earned' => $earned,
            'newly_earned' => $newlyEarned,
            'streak' => $user->fresh()->current_streak ?? 0,
            'last_active_date' => $user->fresh()->last_active_date,
        ]);
    }
}
