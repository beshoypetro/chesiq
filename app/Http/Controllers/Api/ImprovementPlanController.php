<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\ImprovementPlan;
use App\Services\ImprovementPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Improvement Plan — durable, adaptive long-horizon plan.
 *
 *   GET  /api/improvement-plan               show (lazily create + sync)
 *   POST /api/improvement-plan/sync          force recompute (throttle:30,1)
 *   POST /api/improvement-plan/action/complete   mark next action done, re-sync
 *
 * Response shape: IMPROVEMENT_PLAN_SPEC.md §9/§10.
 */
class ImprovementPlanController extends Controller
{
    public function __construct(private readonly ImprovementPlanService $service) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->placement_completed_at && $user->placement_elo === null) {
            return response()->json(['plan' => ['placement_completed' => false]]);
        }

        $plan = $this->service->sync($user);

        return response()->json(['plan' => $this->payload($plan, $user)]);
    }

    public function sync(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->placement_completed_at && $user->placement_elo === null) {
            return response()->json(['plan' => ['placement_completed' => false], 'changed' => false, 'changes' => []]);
        }

        $before = ImprovementPlan::where('user_id', $user->id)->first();
        $beforeLogCount = $before ? count($before->adaptation_log_json ?? []) : 0;
        // Fingerprint the user-visible plan OUTPUT (not the input signals_hash,
        // which an activity hook may already have rewritten in an earlier
        // request) so `changed` honestly reflects whether the plan differs.
        $beforePrint = $before ? $this->outputFingerprint($before) : null;

        $plan = $this->service->sync($user, force: true);

        $log = $plan->adaptation_log_json ?? [];
        $newEvents = array_slice($log, $beforeLogCount);
        $changed = $before === null || $beforePrint !== $this->outputFingerprint($plan);

        return response()->json([
            'plan' => $this->payload($plan, $user),
            'changed' => $changed,
            'changes' => array_values($newEvents),
        ]);
    }

    public function completeAction(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->placement_completed_at && $user->placement_elo === null) {
            return response()->json(['plan' => ['placement_completed' => false]]);
        }

        // The action's effect on the underlying signals is what advances the
        // plan (a completed puzzle/activity changes counts). Re-sync to surface
        // the next action and any adaptation events.
        $plan = $this->service->sync($user, force: true);

        return response()->json([
            'plan' => $this->payload($plan, $user),
            'next_action' => $plan->next_action_json,
        ]);
    }

    /**
     * Stable fingerprint of the user-visible plan output. Used to decide whether
     * an explicit re-sync actually changed anything the user would see.
     */
    private function outputFingerprint(ImprovementPlan $plan): string
    {
        return sha1((string) json_encode([
            $plan->readiness_score,
            $plan->current_milestone_slug,
            $plan->milestones_json,
            $plan->skill_mastery_json,
            $plan->next_action_json,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ImprovementPlan $plan, $user): array
    {
        $trainerId = $plan->trainer_id ?: 'king';
        $trainerCfg = config("trainers.{$trainerId}") ?? config('trainers.king');

        $milestones = $plan->milestones_json ?? [];
        $current = collect($milestones)->firstWhere('slug', $plan->current_milestone_slug)
            ?? ($milestones[0] ?? null);

        // skill_mastery as an ordered list keyed by dimension (spec §9 array shape).
        $masteryMap = $plan->skill_mastery_json ?? [];
        $skillMastery = [];
        foreach (ImprovementPlanService::DIMENSIONS as $key) {
            $dim = $masteryMap[$key] ?? null;
            if ($dim === null) {
                continue;
            }
            $skillMastery[] = array_merge(['key' => $key], $dim);
        }

        $weekly = $this->service->latestWeeklyPlan($user);

        // --- ranking / elo-delta block ---
        $rankingBlock = $this->buildRankingBlock($plan, $user);

        return [
            'readiness_score' => $plan->readiness_score,
            'trainer' => [
                'id' => $trainerId,
                'name' => $trainerCfg['name'] ?? 'The Grandmaster',
                'voice_model' => $trainerCfg['voice_model'] ?? null,
                'intro' => $plan->trainer_intro,
            ],
            'current_milestone' => $current,
            'milestones' => $milestones,
            'skill_mastery' => $skillMastery,
            'next_action' => $plan->next_action_json,
            'this_week' => $weekly?->plan_json,
            'adaptation_log' => $plan->adaptation_log_json ?? [],
            'placement_completed' => true,
            'generated_at' => $plan->generated_at?->toISOString(),
            'last_synced_at' => $plan->last_synced_at?->toISOString(),
            'ranking' => $rankingBlock,
            'peer_benchmark' => $this->service->peerBenchmark($user, $plan),
        ];
    }

    /**
     * Build the elo-delta block for the improvement-plan payload.
     *
     * Returns:
     *   {
     *     placement_elo_at_creation: int|null,
     *     current_elo: int|null,
     *     elo_delta: int|null,
     *   }
     *
     * current_elo = latest game rating (fallback users.placement_elo).
     * elo_delta   = current_elo - placement_elo_at_creation (null-safe).
     *
     * @param  \App\Models\ImprovementPlan  $plan
     * @param  mixed  $user
     * @return array<string, int|null>
     */
    private function buildRankingBlock(ImprovementPlan $plan, $user): array
    {
        $placementEloAtCreation = $plan->placement_elo_at_creation;

        // Resolve current ELO: the user's most recent rated game, or placement_elo fallback.
        $currentElo = null;

        $latestGame = Game::where('user_id', $user->id)
            ->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->where('user_color', 'white')->whereNotNull('white_rating');
                })->orWhere(function ($q2) {
                    $q2->where('user_color', 'black')->whereNotNull('black_rating');
                });
            })
            ->latest('played_at')
            ->first(['user_color', 'white_rating', 'black_rating']);

        if ($latestGame !== null) {
            $currentElo = $latestGame->user_color === 'white'
                ? $latestGame->white_rating
                : $latestGame->black_rating;
        }

        if ($currentElo === null) {
            $currentElo = $user->placement_elo ?? null;
        }

        $eloDelta = ($currentElo !== null && $placementEloAtCreation !== null)
            ? ($currentElo - $placementEloAtCreation)
            : null;

        return [
            'placement_elo_at_creation' => $placementEloAtCreation,
            'current_elo'               => $currentElo,
            'elo_delta'                 => $eloDelta,
        ];
    }
}
