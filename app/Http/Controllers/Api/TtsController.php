<?php

namespace App\Http\Controllers\Api;

use App\Services\PiperTtsService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class TtsController extends Controller
{
    /** Daily per-user cap. Voice fires on every move — higher than coach/commentary. */
    private const DAILY_TTS_CAP = 2000;

    public function synthesize(Request $request, PiperTtsService $piper): HttpResponse
    {
        $v = $request->validate([
            'text' => 'required|string|max:2000',
            'voice' => 'nullable|string|max:40',
        ]);

        $userId = $request->user()->id;
        $dayKey = "tts_daily:{$userId}:".now()->format('Y-m-d');
        $count = (int) Cache::get($dayKey, 0);
        if ($count >= self::DAILY_TTS_CAP) {
            return response('', 429);
        }

        $bytes = $piper->synthesize($v['text'], $v['voice'] ?? null);
        if ($bytes === null) {
            // Caller falls back to browser speechSynthesis on non-200
            return response('', 503);
        }

        // Only count successful generations against the daily quota
        Cache::put($dayKey, $count + 1, now()->endOfDay());

        return response($bytes, 200, [
            'Content-Type' => 'audio/wav',
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
