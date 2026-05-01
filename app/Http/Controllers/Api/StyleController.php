<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StyleAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StyleController extends Controller
{
    public function __construct(private StyleAnalysisService $service) {}

    /**
     * GET /api/insights/style — return the user's playing style profile.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Try cached profile
        $cached = $user->style_profile_json ? json_decode($user->style_profile_json, true) : null;

        // Recompute if stale (older than 24h) or missing
        $recompute = true;
        if ($cached && isset($cached['computed_at'])) {
            $age = now()->diffInHours($cached['computed_at']);
            $recompute = $age > 24;
        }

        if ($recompute) {
            $result = $this->service->compute($user);
            $result['computed_at'] = now()->toISOString();
            $user->update(['style_profile_json' => json_encode($result)]);
            $cached = $result;
        }

        return response()->json(['data' => $cached]);
    }
}
