<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserPlan;
use App\Services\PlanGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * V2 Phase H — per-user weekly study plan.
 *
 *   POST /api/plan/generate   trigger generation (also fetched if fresh)
 *   GET  /api/plan/current    return latest non-expired plan
 *
 * Plans are cached server-side for `expires_at` days (default 7) so a
 * refresh doesn't burn quota every page load.
 */
class PlanController extends Controller
{
    public function current(Request $request): JsonResponse
    {
        $plan = $this->latestNonExpired($request->user()->id);

        if (! $plan) {
            return response()->json(['plan' => null], 200);
        }

        return response()->json([
            'plan' => $this->formatPlan($plan),
        ]);
    }

    public function generate(Request $request, PlanGeneratorService $service): JsonResponse
    {
        $user = $request->user();

        // If a fresh one exists already, return it — keeps the LLM bill low and
        // avoids surprising the user with a different plan on every refresh.
        $existing = $this->latestNonExpired($user->id);
        if ($existing && ! $request->boolean('force')) {
            return response()->json([
                'plan' => $this->formatPlan($existing),
                'cached' => true,
            ]);
        }

        $payload = $service->generate($user);

        $plan = UserPlan::create([
            'user_id' => $user->id,
            'generated_at' => now(),
            'trainer_id' => $user->selected_trainer_id,
            'elo_at_generation' => $user->placement_elo ?? null,
            'intents_json' => is_array($user->intents) ? $user->intents : [],
            'plan_json' => $payload,
            'expires_at' => now()->addDays(7),
        ]);

        return response()->json([
            'plan' => $this->formatPlan($plan),
            'cached' => false,
        ], 201);
    }

    private function latestNonExpired(int $userId): ?UserPlan
    {
        return UserPlan::where('user_id', $userId)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('generated_at')
            ->first();
    }

    private function formatPlan(UserPlan $p): array
    {
        return [
            'id' => $p->id,
            'generated_at' => $p->generated_at?->toISOString(),
            'trainer_id' => $p->trainer_id,
            'elo_at_generation' => $p->elo_at_generation,
            'intents' => $p->intents_json ?? [],
            'plan' => $p->plan_json,
            'expires_at' => $p->expires_at?->toISOString(),
        ];
    }
}
