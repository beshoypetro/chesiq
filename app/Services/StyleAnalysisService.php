<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class StyleAnalysisService
{
    /**
     * Historical world champion archetypes for comparison.
     */
    private const ARCHETYPES = [
        'Tal'      => ['attacking' => 95, 'tactical' => 90, 'positional' => 40, 'defensive' => 30, 'endgame' => 55],
        'Petrosian' => ['attacking' => 30, 'tactical' => 50, 'positional' => 95, 'defensive' => 95, 'endgame' => 80],
        'Karpov'   => ['attacking' => 50, 'tactical' => 65, 'positional' => 90, 'defensive' => 85, 'endgame' => 90],
        'Kasparov' => ['attacking' => 85, 'tactical' => 85, 'positional' => 80, 'defensive' => 60, 'endgame' => 75],
    ];

    public function compute(User $user): array
    {
        $games = $user->games()
            ->whereNotNull('analyzed_at')
            ->orderByDesc('played_at')
            ->limit(50)
            ->get(['id', 'user_color', 'result']);

        if ($games->isEmpty()) {
            return $this->empty();
        }

        $gameIds = $games->pluck('id');

        // Fetch move analyses for user's moves only
        $moves = DB::table('move_analyses as ma')
            ->join('games as g', 'ma.game_id', '=', 'g.id')
            ->whereIn('ma.game_id', $gameIds)
            ->where('g.user_id', $user->id)
            ->whereRaw('ma.color = g.user_color')
            ->select('ma.classification', 'ma.cp_loss', 'ma.eval_before', 'ma.eval_after', 'ma.move_number')
            ->get();

        $total = $moves->count();
        if ($total === 0) {
            return $this->empty();
        }

        // Attacking: percentage of moves with positive cp swings (sacrifices, aggressive)
        $sacrifices = $moves->filter(fn ($m) =>
            $m->classification === 'brilliant' ||
            ($m->cp_loss !== null && $m->cp_loss < 0 && abs($m->cp_loss) > 30)
        )->count();

        // Tactical: brilliant + best move rate
        $tactical = $moves->filter(fn ($m) =>
            in_array($m->classification, ['brilliant', 'best', 'excellent'])
        )->count();

        // Endgame: accuracy in endgame phase (move > 40)
        $endgameMoves = $moves->filter(fn ($m) => $m->move_number > 40);
        $endgameGood = $endgameMoves->filter(fn ($m) =>
            in_array($m->classification, ['best', 'excellent', 'good', 'brilliant'])
        )->count();

        // Defensive: inaccuracy rate in middlegame (fewer blunders = more solid/defensive)
        $middlegameMoves = $moves->filter(fn ($m) => $m->move_number >= 10 && $m->move_number <= 40);
        $middlegameBlunders = $middlegameMoves->filter(fn ($m) =>
            in_array($m->classification, ['blunder', 'mistake'])
        )->count();

        $attacking  = min(100, round(($sacrifices / max(1, $total)) * 100 * 8));
        $tactical   = min(100, round(($tactical / max(1, $total)) * 100 * 2));
        $positional = min(100, max(0, 100 - $attacking));
        $defensive  = min(100, max(0, 100 - round(($middlegameBlunders / max(1, $middlegameMoves->count())) * 200)));
        $endgame    = $endgameMoves->count() > 0
            ? min(100, round(($endgameGood / $endgameMoves->count()) * 100))
            : 50;

        $profile = compact('attacking', 'tactical', 'positional', 'defensive', 'endgame');

        // Find closest archetype
        $bestMatch = null;
        $bestDist = PHP_INT_MAX;
        foreach (self::ARCHETYPES as $name => $arch) {
            $dist = 0;
            foreach ($profile as $dim => $val) {
                $dist += abs($val - ($arch[$dim] ?? 50));
            }
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $bestMatch = $name;
            }
        }

        return [
            'profile' => $profile,
            'closest_archetype' => $bestMatch,
            'games_analyzed' => $games->count(),
            'archetypes' => self::ARCHETYPES,
        ];
    }

    private function empty(): array
    {
        return [
            'profile' => ['attacking' => 0, 'tactical' => 0, 'positional' => 0, 'defensive' => 0, 'endgame' => 0],
            'closest_archetype' => null,
            'games_analyzed' => 0,
            'archetypes' => self::ARCHETYPES,
        ];
    }
}
