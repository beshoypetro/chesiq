<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\AcademyController;
use App\Models\AssessmentPosition;
use App\Models\AssessmentResponse;
use App\Models\UserAssessment;
use App\Services\AdaptiveAssessmentService;
use App\Services\ImprovementPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AssessmentController extends Controller
{
    public function start(Request $request, AdaptiveAssessmentService $svc): JsonResponse
    {
        $user = $request->user();

        $assessment = UserAssessment::create([
            'user_id' => $user->id,
            'started_at' => now(),
            'current_elo_estimate' => AdaptiveAssessmentService::STARTING_ELO,
            'confidence_interval' => AdaptiveAssessmentService::STARTING_CI,
        ]);

        $position = $svc->nextPosition($assessment);

        return response()->json([
            'assessment_id' => $assessment->id,
            'question' => $position ? $this->serializePosition($position) : null,
            'progress' => ['answered' => 0, 'min' => AdaptiveAssessmentService::MIN_QUESTIONS, 'max' => AdaptiveAssessmentService::MAX_QUESTIONS],
        ]);
    }

    public function answer(Request $request, AdaptiveAssessmentService $svc): JsonResponse
    {
        $data = $request->validate([
            'assessment_id' => 'required|integer|exists:user_assessments,id',
            'position_id' => 'required|integer|exists:assessment_positions,id',
            'answer' => 'required',
            'response_ms' => 'nullable|integer|min:0|max:600000',
        ]);

        $assessment = UserAssessment::where('id', $data['assessment_id'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if ($assessment->completed_at) {
            return response()->json(['message' => 'Assessment already completed'], 422);
        }

        $position = AssessmentPosition::findOrFail($data['position_id']);
        $isCorrect = $this->isCorrect($position, $data['answer']);
        $eloChange = $svc->applyResponse($assessment, $position, $isCorrect);

        AssessmentResponse::create([
            'assessment_id' => $assessment->id,
            'position_id' => $position->id,
            'theme' => $position->theme,
            'answer' => is_array($data['answer']) ? $data['answer'] : ['value' => $data['answer']],
            'is_correct' => $isCorrect,
            'response_ms' => $data['response_ms'] ?? null,
            'elo_before' => $eloChange['elo_before'],
            'elo_after' => $eloChange['elo_after'],
        ]);

        $assessment->refresh();

        if ($svc->shouldTerminate($assessment)) {
            $svc->finalize($assessment);
            $fresh = $request->user()->fresh();
            // Placement finalized → enroll into the recommended track's first
            // course so the academy loop has a real starting point.
            $this->autoEnroll($fresh);
            // Re-assessment finalized → full plan recompute, announce new band
            // if changed (spec §7). Defensive — never break the result response.
            $this->syncImprovementPlan($fresh);
            return $this->result($request, $assessment->id);
        }

        $next = $svc->nextPosition($assessment);

        return response()->json([
            'is_correct' => $isCorrect,
            'explanation' => $position->explanation,
            'elo_estimate' => $assessment->current_elo_estimate,
            'progress' => [
                'answered' => $assessment->responses()->count(),
                'min' => AdaptiveAssessmentService::MIN_QUESTIONS,
                'max' => AdaptiveAssessmentService::MAX_QUESTIONS,
            ],
            'question' => $next ? $this->serializePosition($next) : null,
            'completed' => false,
        ]);
    }

    public function result(Request $request, int $id): JsonResponse
    {
        $assessment = UserAssessment::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return response()->json([
            'completed' => (bool) $assessment->completed_at,
            'elo_estimate' => $assessment->final_elo_estimate ?? $assessment->current_elo_estimate,
            'weakness_profile' => $assessment->weakness_profile_json ?? [],
            'recommended_track' => $assessment->recommended_track,
            'started_at' => $assessment->started_at,
            'completed_at' => $assessment->completed_at,
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $u = $request->user();
        return response()->json([
            'placement_completed' => (bool) $u->placement_completed_at,
            'placement_elo' => $u->placement_elo,
            'placement_track' => $u->placement_track,
        ]);
    }

    /**
     * V2 Phase G — skip the placement quiz, infer Elo from game history.
     * Returns the same shape as a completed assessment.
     */
    public function estimateFromGames(Request $request, AdaptiveAssessmentService $svc): JsonResponse
    {
        $result = $svc->estimateFromGames($request->user());

        $fresh = $request->user()->fresh();
        // Placement set from game history → enroll into the recommended track.
        $this->autoEnroll($fresh);
        // Placement set from game history → full plan recompute (spec §7).
        $this->syncImprovementPlan($fresh);

        return response()->json($result);
    }

    /**
     * Defensive academy auto-enroll — enroll the placed user into the
     * recommended track's first course. A failure here must never break
     * placement, so it is fully wrapped in try/catch.
     */
    private function autoEnroll(?\App\Models\User $user): void
    {
        if (! $user || ! $user->placement_track) {
            return;
        }
        try {
            app(AcademyController::class)->autoEnrollFromPlacement($user->id, $user->placement_track);
        } catch (\Throwable $e) {
            Log::warning('Academy auto-enroll (placement) failed', ['error' => $e->getMessage()]);
        }
    }

    /** Defensive plan sync — a failure here must never break assessment. */
    private function syncImprovementPlan(?\App\Models\User $user): void
    {
        if (! $user) {
            return;
        }
        try {
            app(ImprovementPlanService::class)->sync($user, force: true);
        } catch (\Throwable $e) {
            Log::warning('ImprovementPlan sync (assessment) failed', ['error' => $e->getMessage()]);
        }
    }

    private function serializePosition(AssessmentPosition $p): array
    {
        return [
            'id' => $p->id,
            'fen' => $p->fen,
            'theme' => $p->theme,
            'kind' => $p->question_kind,
            'payload' => $p->payload,
        ];
    }

    private function isCorrect(AssessmentPosition $p, mixed $answer): bool
    {
        $expected = $p->correct_answer;

        if ($p->question_kind === 'best_move') {
            $given = is_array($answer) ? ($answer['move'] ?? null) : $answer;
            return is_string($given) && in_array(strtolower($given), array_map('strtolower', $expected['moves'] ?? []), true);
        }

        // multiple_choice / plan_select — payload has options keyed 0..n, correct_answer has 'index'
        $given = is_array($answer) ? ($answer['index'] ?? null) : $answer;
        return (int) $given === (int) ($expected['index'] ?? -1);
    }
}
