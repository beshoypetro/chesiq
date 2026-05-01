<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StudyPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudyPlanController extends Controller
{
    public function __construct(private StudyPlanService $service) {}

    /**
     * GET /api/study-plan — return this week's 7-day plan with any user overrides.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $plan = $this->service->generate($user);

        // Apply overrides
        $overrides = DB::table('study_plan_overrides')
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('day_index');

        foreach ($plan as &$day) {
            $override = $overrides[$day['day_index']] ?? null;
            if ($override && $override->activity_json) {
                $day['activities'] = json_decode($override->activity_json, true);
                $day['overridden'] = true;
            } else {
                $day['overridden'] = false;
            }
        }

        return response()->json(['plan' => array_values($plan)]);
    }

    /**
     * POST /api/study-plan/override — override a day's activities.
     */
    public function override(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'day_index' => 'required|integer|min:0|max:6',
            'activity_json' => 'nullable|string',
        ]);

        DB::table('study_plan_overrides')->updateOrInsert(
            ['user_id' => $user->id, 'day_index' => $data['day_index']],
            ['activity_json' => $data['activity_json'] ?? null]
        );

        return response()->json(['message' => 'Override saved.']);
    }
}
