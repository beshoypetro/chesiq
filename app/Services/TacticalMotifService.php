<?php

namespace App\Services;

/**
 * Heuristic tactical-motif detection on a position. Lightweight by design —
 * we want to give Gemini a few concrete, true facts to anchor its commentary,
 * not replicate Stockfish. False negatives are fine; false positives are not,
 * because the LLM will faithfully repeat them. Each detector either adds a
 * motif string or returns silently.
 *
 * Coordinate convention: rank 1 = white's back rank (index 0 from the bottom
 * when iterating the FEN board top-down with rank 8 first).
 */
class TacticalMotifService
{
    /**
     * @return string[] motifs detected, e.g. ["hanging black knight on f6", "white back-rank weakness"]
     */
    public function detect(string $fenBefore, ?string $fenAfter, ?string $playedSan): array
    {
        $boardBefore = $this->parseBoard($fenBefore);
        if ($boardBefore === null) {
            return [];
        }
        $sideToMove = $this->sideToMove($fenBefore);

        $out = [];
        $this->detectHangingPieces($boardBefore, $sideToMove, $out);
        $this->detectBackRankWeakness($boardBefore, $out);
        $this->detectExposedKing($boardBefore, $out);

        // De-duplicate while preserving order.
        return array_values(array_unique($out));
    }

    /**
     * Returns 8x8 board indexed [rank 0..7][file 0..7] where rank 0 is white's
     * back rank. Each cell is a piece char or null.
     */
    private function parseBoard(string $fen): ?array
    {
        $parts = explode(' ', trim($fen));
        if (count($parts) < 1) return null;
        $rows = explode('/', $parts[0]);
        if (count($rows) !== 8) return null;

        $board = array_fill(0, 8, array_fill(0, 8, null));
        // FEN starts at rank 8 (top) → board index 7. Walk top-down.
        foreach ($rows as $rIdx => $row) {
            $rank = 7 - $rIdx;
            $file = 0;
            for ($i = 0, $n = strlen($row); $i < $n; $i++) {
                $ch = $row[$i];
                if (ctype_digit($ch)) {
                    $file += (int) $ch;
                    continue;
                }
                if ($file > 7) return null;
                $board[$rank][$file] = $ch;
                $file++;
            }
            if ($file !== 8) return null;
        }
        return $board;
    }

    private function sideToMove(string $fen): string
    {
        $parts = explode(' ', trim($fen));
        return ($parts[1] ?? 'w') === 'b' ? 'b' : 'w';
    }

    /**
     * Naive hanging-piece detection: a piece is "hanging" if it is not defended
     * by any same-color piece AND can be captured by any opposing piece for
     * a minor-or-better material gain. Pawns excluded (too noisy).
     */
    private function detectHangingPieces(array $board, string $sideToMove, array &$out): void
    {
        for ($rank = 0; $rank < 8; $rank++) {
            for ($file = 0; $file < 8; $file++) {
                $p = $board[$rank][$file];
                if ($p === null) continue;
                if (strtolower($p) === 'p' || strtolower($p) === 'k') continue;
                $color = ctype_upper($p) ? 'w' : 'b';
                $defenders = $this->attackersOf($board, $rank, $file, $color);
                $attackers = $this->attackersOf($board, $rank, $file, $color === 'w' ? 'b' : 'w');
                if (count($attackers) === 0) continue;
                if (count($defenders) > 0) continue;
                $sq = $this->square($file, $rank);
                $name = $this->pieceName($p);
                $colorWord = $color === 'w' ? 'white' : 'black';
                $out[] = "the {$colorWord} {$name} on {$sq} is undefended and attacked";
            }
        }
        // Mention turn so the coach knows which side has tempo to grab.
        if (! empty($out)) {
            $out[] = ($sideToMove === 'w' ? 'white' : 'black') . ' has the move';
        }
    }

    /**
     * Back-rank weakness: friendly king on its first rank, blocked by its
     * own pawns on the same rank in front, and no escape file via the rook
     * file. Coarse signal — exact mating net is for the LLM to spot.
     */
    private function detectBackRankWeakness(array $board, array &$out): void
    {
        foreach (['w' => 0, 'b' => 7] as $color => $rank) {
            $kingFile = null;
            for ($f = 0; $f < 8; $f++) {
                $p = $board[$rank][$f];
                if ($p !== null && strtolower($p) === 'k' && (ctype_upper($p) === ($color === 'w'))) {
                    $kingFile = $f;
                    break;
                }
            }
            if ($kingFile === null) continue;

            $pawnRank = $color === 'w' ? 1 : 6;
            $blocked = 0;
            $checked = 0;
            for ($df = -1; $df <= 1; $df++) {
                $f = $kingFile + $df;
                if ($f < 0 || $f > 7) continue;
                $checked++;
                $p = $board[$pawnRank][$f];
                if ($p !== null && strtolower($p) === 'p' && (ctype_upper($p) === ($color === 'w'))) {
                    $blocked++;
                }
            }
            if ($checked > 0 && $blocked === $checked) {
                $colorWord = $color === 'w' ? 'white' : 'black';
                $out[] = "{$colorWord} has a back-rank weakness — the king on " . $this->square($kingFile, $rank) . " is sealed in by its own pawns";
            }
        }
    }

    /**
     * King well outside its starting region with friendly pieces still on
     * the back rank — typical "king in the open" warning sign.
     */
    private function detectExposedKing(array $board, array &$out): void
    {
        foreach (['w', 'b'] as $color) {
            for ($r = 0; $r < 8; $r++) {
                for ($f = 0; $f < 8; $f++) {
                    $p = $board[$r][$f];
                    if ($p === null) continue;
                    if (strtolower($p) !== 'k') continue;
                    if (ctype_upper($p) !== ($color === 'w')) continue;
                    $homeRank = $color === 'w' ? 0 : 7;
                    if (abs($r - $homeRank) >= 3) {
                        $out[] = ($color === 'w' ? 'white' : 'black') . ' king is exposed on ' . $this->square($f, $r);
                    }
                }
            }
        }
    }

    /**
     * @return array list of [rank, file] of pieces of $byColor that attack (rank,file).
     */
    private function attackersOf(array $board, int $rank, int $file, string $byColor): array
    {
        $attackers = [];
        $isWhite = $byColor === 'w';
        // Pawns
        $pawnDir = $isWhite ? -1 : 1; // pawns attack from one rank below (white) or above (black)
        foreach ([-1, 1] as $df) {
            $r = $rank + $pawnDir;
            $f = $file + $df;
            if ($r < 0 || $r > 7 || $f < 0 || $f > 7) continue;
            $p = $board[$r][$f] ?? null;
            if ($p !== null && strtolower($p) === 'p' && ctype_upper($p) === $isWhite) {
                $attackers[] = [$r, $f];
            }
        }
        // Knights
        foreach ([[1,2],[2,1],[-1,2],[-2,1],[1,-2],[2,-1],[-1,-2],[-2,-1]] as [$dr, $df]) {
            $r = $rank + $dr;
            $f = $file + $df;
            if ($r < 0 || $r > 7 || $f < 0 || $f > 7) continue;
            $p = $board[$r][$f] ?? null;
            if ($p !== null && strtolower($p) === 'n' && ctype_upper($p) === $isWhite) {
                $attackers[] = [$r, $f];
            }
        }
        // Sliders: bishops/rooks/queens
        $rays = [
            'bq' => [[1,1],[1,-1],[-1,1],[-1,-1]],
            'rq' => [[1,0],[-1,0],[0,1],[0,-1]],
        ];
        foreach ($rays as $kinds => $dirs) {
            foreach ($dirs as [$dr, $df]) {
                for ($d = 1; $d < 8; $d++) {
                    $r = $rank + $dr * $d;
                    $f = $file + $df * $d;
                    if ($r < 0 || $r > 7 || $f < 0 || $f > 7) break;
                    $p = $board[$r][$f] ?? null;
                    if ($p === null) continue;
                    $piece = strtolower($p);
                    $matches = $kinds === 'bq'
                        ? ($piece === 'b' || $piece === 'q')
                        : ($piece === 'r' || $piece === 'q');
                    if ($matches && ctype_upper($p) === $isWhite) {
                        $attackers[] = [$r, $f];
                    }
                    break; // any piece blocks the ray
                }
            }
        }
        // Kings (adjacent)
        for ($dr = -1; $dr <= 1; $dr++) {
            for ($df = -1; $df <= 1; $df++) {
                if ($dr === 0 && $df === 0) continue;
                $r = $rank + $dr;
                $f = $file + $df;
                if ($r < 0 || $r > 7 || $f < 0 || $f > 7) continue;
                $p = $board[$r][$f] ?? null;
                if ($p !== null && strtolower($p) === 'k' && ctype_upper($p) === $isWhite) {
                    $attackers[] = [$r, $f];
                }
            }
        }
        return $attackers;
    }

    private function square(int $file, int $rank): string
    {
        return chr(ord('a') + $file) . ($rank + 1);
    }

    private function pieceName(string $p): string
    {
        return match (strtolower($p)) {
            'p' => 'pawn',
            'n' => 'knight',
            'b' => 'bishop',
            'r' => 'rook',
            'q' => 'queen',
            'k' => 'king',
            default => 'piece',
        };
    }
}
