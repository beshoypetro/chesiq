<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LlmCallLog;
use App\Models\TeacherConversation;
use App\Models\TeacherMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TelemetryController extends Controller
{
    public function dashboard(): JsonResponse
    {
        $since30 = Carbon::now()->subDays(30);

        $llm = LlmCallLog::where('created_at', '>=', $since30)
            ->selectRaw('endpoint, COUNT(*) as calls, SUM(tokens_in) as tin, SUM(tokens_out) as tout, ' .
                'SUM(CASE WHEN cache_hit THEN 1 ELSE 0 END) as cache_hits, ' .
                'SUM(cost_estimate) as cost')
            ->groupBy('endpoint')
            ->get();

        $convCount = TeacherConversation::where('started_at', '>=', $since30)->count();
        $msgCount = TeacherMessage::where('created_at', '>=', $since30)->count();
        $avgConvLength = $convCount > 0 ? round($msgCount / $convCount, 1) : 0;

        $weeklyActive = User::where('updated_at', '>=', Carbon::now()->subDays(7))->count();

        $moduleCompletion = DB::table('module_progress')
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $since30)
            ->count();

        $costPerActiveUser = $weeklyActive > 0
            ? round(($llm->sum('cost') ?? 0) / $weeklyActive, 4)
            : 0;

        return response()->json([
            'window_days' => 30,
            'llm_by_endpoint' => $llm,
            'teacher' => [
                'conversations' => $convCount,
                'messages' => $msgCount,
                'avg_length' => $avgConvLength,
            ],
            'weekly_active_users' => $weeklyActive,
            'module_completions_30d' => $moduleCompletion,
            'cost_per_active_user' => $costPerActiveUser,
        ]);
    }
}
