<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TeacherConversation;
use App\Services\SpeechTranscriptionService;
use App\Services\TeacherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class TeacherController extends Controller
{
    /** Per-user daily cap on teacher turns; kept high so tests never trip it. */
    private const DAILY_TURN_CAP = 100;

    public function listConversations(Request $request): JsonResponse
    {
        $convs = TeacherConversation::where('user_id', $request->user()->id)
            ->orderByDesc('last_message_at')
            ->limit(30)
            ->get(['id', 'started_at', 'last_message_at', 'summary_text']);

        return response()->json(['conversations' => $convs]);
    }

    public function start(Request $request, TeacherService $svc): JsonResponse
    {
        $conv = $svc->startConversation($request->user());
        return response()->json(['conversation_id' => $conv->id]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $conv = TeacherConversation::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->with('messages')
            ->firstOrFail();

        return response()->json([
            'id' => $conv->id,
            'started_at' => $conv->started_at,
            'messages' => $conv->messages->map(fn ($m) => [
                'id' => $m->id,
                'role' => $m->role,
                'mode' => $m->mode,
                'content' => $m->content,
                'created_at' => $m->created_at,
            ]),
        ]);
    }

    public function message(Request $request, TeacherService $svc): JsonResponse
    {
        $data = $request->validate([
            'conversation_id' => 'required|integer|exists:teacher_conversations,id',
            'message' => 'required|string|max:2000',
            'mode_override' => 'nullable|in:tutor,socratic,debate',
            'fen' => 'nullable|string|max:120',
            'module_id' => 'nullable|integer|exists:modules,id',
            'activity_id' => 'nullable|integer',
        ]);

        $user = $request->user();

        // Per-user daily turn cap — mirrors ChatController::chat. Friendly 429,
        // never a 500.
        $dayKey = "teacher_daily:{$user->id}:".now()->format('Y-m-d');
        $count = (int) Cache::get($dayKey, 0);
        if ($count >= self::DAILY_TURN_CAP) {
            return response()->json([
                'message' => "We've reached today's teaching limit. Let's pick this up again tomorrow.",
                'rate_limit' => true,
            ], 429);
        }
        Cache::put($dayKey, $count + 1, now()->endOfDay());

        $conv = TeacherConversation::where('id', $data['conversation_id'])
            ->where('user_id', $user->id)
            ->firstOrFail();

        $reply = $svc->reply(
            $user,
            $conv,
            $data['message'],
            $data['mode_override'] ?? null,
            $data['fen'] ?? null,
            isset($data['module_id']) ? (int) $data['module_id'] : null,
            isset($data['activity_id']) ? (int) $data['activity_id'] : null,
        );

        return response()->json([
            'message' => [
                'id' => $reply->id,
                'role' => $reply->role,
                'mode' => $reply->mode,
                'content' => $reply->content,
                'created_at' => $reply->created_at,
            ],
        ]);
    }

    /**
     * Server-side speech-to-text fallback for browsers without the Web Speech API
     * (Safari / Firefox). Accepts a short audio clip and returns its transcription.
     *
     * - 200 {text}      — transcribed successfully
     * - 501             — no transcription backend configured (client keeps using
     *                     its own in-browser recognition where available)
     * - 422 {message}   — backend configured but the clip couldn't be transcribed
     */
    public function transcribe(Request $request, SpeechTranscriptionService $stt): JsonResponse
    {
        $request->validate([
            'audio' => 'required|file|max:'.SpeechTranscriptionService::MAX_KILOBYTES,
        ]);

        if (! $stt->isConfigured()) {
            return response()->json([
                'message' => 'Server transcription is not enabled. Your browser\'s built-in voice input is used where available.',
            ], 501);
        }

        $text = $stt->transcribe($request->file('audio'));
        if ($text === null) {
            return response()->json([
                'message' => "We couldn't make out that audio. Please try again or type your message.",
            ], 422);
        }

        return response()->json(['text' => $text]);
    }
}
