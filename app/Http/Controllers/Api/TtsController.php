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

    /**
     * Pre-synthesize upcoming lines into the Piper disk cache so the later
     * /tts call for the same text is a near-instant cache hit. Bytes are
     * discarded — this endpoint exists purely to warm the cache ahead of
     * playback (walkthrough prefetch, session line catalogs).
     */
    public function prewarm(Request $request, PiperTtsService $piper)
    {
        $v = $request->validate([
            'texts' => 'required|array|min:1|max:3',
            'texts.*' => 'required|string|max:400',
            'voice' => 'nullable|string|max:40',
        ]);

        $userId = $request->user()->id;
        $dayKey = "tts_daily:{$userId}:".now()->format('Y-m-d');
        $count = (int) Cache::get($dayKey, 0);
        if ($count >= self::DAILY_TTS_CAP) {
            return response()->json(['warmed' => 0], 429);
        }

        $warmed = 0;
        foreach ($v['texts'] as $text) {
            if ($count + $warmed >= self::DAILY_TTS_CAP) {
                break;
            }
            if ($piper->synthesize($text, $v['voice'] ?? null) !== null) {
                $warmed++;
            }
        }

        if ($warmed > 0) {
            Cache::put($dayKey, $count + $warmed, now()->endOfDay());
        }

        return response()->json(['warmed' => $warmed]);
    }
}
