<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TeacherConversation;
use App\Services\TeacherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherController extends Controller
{
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
        ]);

        $conv = TeacherConversation::where('id', $data['conversation_id'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $reply = $svc->reply($request->user(), $conv, $data['message'], $data['mode_override'] ?? null);

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

    public function transcribe(Request $request): JsonResponse
    {
        // Stub for Whisper fallback — Web Speech API handles transcription client-side
        // for supported browsers. Returns 501 until the Whisper integration ships in
        // Week 8.
        return response()->json(['message' => 'Server transcription not yet enabled.'], 501);
    }
}
