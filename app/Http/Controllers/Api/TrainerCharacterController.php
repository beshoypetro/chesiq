<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * V2 trainer characters — the 5 selectable personas (king/queen/knight/bishop/rook).
 *
 * Distinct from the existing TrainerController, which owns the opening-drill
 * voice-coach feature. Naming kept that controller stable so we don't break
 * the existing /api/training/* routes.
 *
 * Spec: CHESSIQ_V2_PLAN.md §4.3.
 */
class TrainerCharacterController extends Controller
{
    /**
     * Public list of all trainer characters. The persona prompt is intentionally
     * NOT exposed — it's a server-side LLM detail, not user-facing data.
     */
    public function index(): JsonResponse
    {
        $trainers = collect(config('trainers', []))
            ->values()
            ->map(fn (array $t) => [
                'id' => $t['id'],
                'name' => $t['name'],
                'piece' => $t['piece'],
                'voice_model' => $t['voice_model'],
                'default_mode' => $t['default_mode'],
                'specialty' => $t['specialty'],
                'tagline' => $t['tagline'] ?? '',
            ])
            ->all();

        return response()->json(['trainers' => $trainers]);
    }

    /**
     * Set the authenticated user's selected trainer.
     *
     * PATCH /api/user/trainer  { "trainer_id": "king" }
     */
    public function select(Request $request): JsonResponse
    {
        $allowed = array_keys((array) config('trainers', []));

        $validator = Validator::make($request->all(), [
            'trainer_id' => ['required', 'string', 'in:'.implode(',', $allowed)],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The selected trainer is not available.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $user->selected_trainer_id = $validator->validated()['trainer_id'];
        $user->save();

        return response()->json([
            'selected_trainer_id' => $user->selected_trainer_id,
        ]);
    }
}
