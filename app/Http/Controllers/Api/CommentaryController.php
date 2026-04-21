<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;

class CommentaryController extends Controller
{
    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'san'            => 'required|string|max:20',
            'classification' => 'required|string|max:20',
            'cp_loss'        => 'nullable|numeric',
            'eval_before'    => 'nullable|numeric',
            'eval_after'     => 'nullable|numeric',
            'best_move_san'  => 'nullable|string|max:20',
            'move_number'    => 'nullable|integer',
            'player_color'   => 'nullable|string|max:10',
            'opening_name'   => 'nullable|string|max:80',
        ]);

        $apiKey = config('services.anthropic.key');
        if (! $apiKey) {
            return response()->json(['commentary' => '']);
        }

        $response = Http::withHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => '2023-06-01',
        ])->timeout(8)->post('https://api.anthropic.com/v1/messages', [
            'model'      => 'claude-haiku-4-5-20251001',
            'max_tokens' => 180,
            'system'     => implode(' ', [
                'You are an elite chess coach providing real-time spoken commentary during post-game analysis.',
                'Your job is to explain in 2 short sentences WHY this move received its rating — the concrete strategic or tactical reason.',
                'Focus on: piece activity, pawn structure, king safety, control of key squares, tempo, threats created or missed.',
                'Do NOT say the move name or rating word — the student already heard those.',
                'Be direct, insightful, educational. Use natural conversational language suitable for text-to-speech.',
                'Example good output: "This keeps the bishop pair intact and pressures the weak d5 square. The alternative would have ceded central control without compensation."',
            ]),
            'messages' => [
                ['role' => 'user', 'content' => $this->buildPrompt($validated)],
            ],
        ]);

        if (! $response->successful()) {
            return response()->json(['commentary' => '']);
        }

        $text = $response->json('content.0.text', '');
        return response()->json(['commentary' => trim($text)]);
    }

    private function buildPrompt(array $d): string
    {
        $cls     = $d['classification'];
        $san     = $d['san'];
        $cpLoss  = isset($d['cp_loss']) ? round((float) $d['cp_loss'] / 100, 2) : 0;
        $evB     = isset($d['eval_before']) ? round((float) $d['eval_before'] / 100, 2) : null;
        $evA     = isset($d['eval_after'])  ? round((float) $d['eval_after']  / 100, 2) : null;
        $best    = $d['best_move_san'] ?? null;
        $moveNum = $d['move_number']   ?? '?';
        $color   = $d['player_color']  ?? 'white';
        $opening = $d['opening_name']  ?? null;

        $parts = ["Move {$moveNum}: {$san} ({$color}) — rated {$cls}."];

        if ($evB !== null && $evA !== null) {
            $parts[] = "Evaluation shifted from {$evB} to {$evA} pawns.";
        }

        if ($cpLoss > 0.05) {
            $parts[] = "This cost {$cpLoss} pawns compared to best play.";
        }

        if ($best && $best !== $san) {
            $parts[] = "The engine preferred {$best} instead.";
        }

        if ($opening) {
            $parts[] = "Opening context: {$opening}.";
        }

        $parts[] = "Explain in 2 sentences why this move is {$cls} — what strategic or tactical principle makes it so?";

        return implode(' ', $parts);
    }
}
