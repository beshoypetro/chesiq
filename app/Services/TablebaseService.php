<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Lichess tablebase proxy for ≤7-piece endgames. Returns a short prose
 * "fact" string (forced mate distance, draw, etc.) the coach can quote
 * verbatim. We skip the call when the position has more than 7 pieces.
 */
class TablebaseService
{
    public function note(string $fen): ?string
    {
        if (! $this->withinTablebaseRange($fen)) {
            return null;
        }
        $key = 'tb:' . sha1($fen);
        return Cache::remember($key, now()->addDays(30), function () use ($fen) {
            try {
                $resp = Http::timeout(8)->get('https://tablebase.lichess.ovh/standard', [
                    'fen' => $fen,
                ]);
            } catch (\Throwable) {
                return null;
            }
            if (! $resp->successful()) return null;
            $data = $resp->json();
            $cat = $data['category'] ?? null;
            $dtm = $data['dtm'] ?? null;
            $dtz = $data['dtz'] ?? null;
            if ($cat === null) return null;

            return match ($cat) {
                'win' => $dtm
                    ? "the side to move has a forced mate in {$dtm}"
                    : "the side to move is winning by tablebase",
                'maybe-win' => "the side to move is winning under the 50-move rule (DTZ {$dtz})",
                'cursed-win' => "winning but blocked by the 50-move rule",
                'draw' => 'this position is a theoretical draw under best play',
                'maybe-loss' => "losing under the 50-move rule (DTZ {$dtz})",
                'blessed-loss' => 'losing but saved by the 50-move rule',
                'loss' => $dtm
                    ? "the side to move is mated in {$dtm}"
                    : 'the side to move is losing by tablebase',
                'unknown' => null,
                default => null,
            };
        });
    }

    private function withinTablebaseRange(string $fen): bool
    {
        $parts = explode(' ', trim($fen));
        if (count($parts) < 1) return false;
        $board = $parts[0];
        $count = 0;
        for ($i = 0, $n = strlen($board); $i < $n; $i++) {
            $ch = $board[$i];
            if (ctype_alpha($ch)) $count++;
        }
        return $count <= 7;
    }
}
