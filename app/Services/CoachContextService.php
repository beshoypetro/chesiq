<?php

namespace App\Services;

use App\Models\Game;
use App\Models\User;
use App\Models\UserPuzzleAttempt;
use App\Models\UserPuzzleRating;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Gathers personalization + position facts the AI coach needs to feel less
 * generic. Two layers: stable (cacheable — rating tier + style archetype +
 * masters popularity) vs ephemeral (per-user, do NOT bake into shared cache —
 * recent weakness themes, full deviation notes). The stable layer is what
 * GeminiCoachService::commentary() uses; the ephemeral layer is reserved for
 * end-of-game synthesis and conversational chat.
 */
class CoachContextService
{
    /**
     * Coarse rating buckets — keeps cache hit rate reasonable while still
     * letting the coach calibrate language to skill level.
     */
    public function ratingTier(User $user): string
    {
        $rating = UserPuzzleRating::where('user_id', $user->id)->value('rating');
        if ($rating === null) {
            return 'unrated';
        }
        if ($rating < 1200) return 'beginner';
        if ($rating < 1600) return 'intermediate';
        if ($rating < 2000) return 'advanced';
        return 'expert';
    }

    /**
     * Closest historical-archetype label from the cached style profile, or
     * null if the user has no analyzed games yet.
     */
    public function styleArchetype(User $user): ?string
    {
        if (! $user->style_profile_json) {
            return null;
        }
        $profile = json_decode($user->style_profile_json, true);
        return $profile['closest_archetype'] ?? null;
    }

    /**
     * Stable, per-(user-bucket, position) context — safe to include in the
     * commentary cache key. Two users in the same rating tier and style
     * archetype get the same coaching for the same blunder.
     */
    public function stableUserContext(User $user): array
    {
        return [
            'rating_tier' => $this->ratingTier($user),
            'style_archetype' => $this->styleArchetype($user),
        ];
    }

    /**
     * Ephemeral per-user context — recent weakness themes and the user's
     * 3 most-frequent blunder motifs. Used for end-of-game synthesis and
     * /chat where personalization outweighs cache hit rate.
     */
    public function ephemeralUserContext(User $user): array
    {
        $weakThemes = $this->recentWeakThemes($user);
        $blunderMotifs = $this->frequentBlunderMotifs($user);

        return [
            'weak_themes' => $weakThemes,
            'frequent_blunder_motifs' => $blunderMotifs,
        ];
    }

    /**
     * Tactical themes the user has been failing on in the last 30 days,
     * limited to top 5. Returns themes like ['fork', 'backRankMate'].
     */
    public function recentWeakThemes(User $user): array
    {
        $since = now()->subDays(30);

        $rows = UserPuzzleAttempt::query()
            ->where('user_puzzle_attempts.user_id', $user->id)
            ->where('user_puzzle_attempts.solved', false)
            ->where('user_puzzle_attempts.created_at', '>=', $since)
            ->join('puzzles', 'user_puzzle_attempts.puzzle_id', '=', 'puzzles.id')
            ->whereNotNull('puzzles.themes')
            ->limit(150)
            ->pluck('puzzles.themes')
            ->all();

        $counts = [];
        foreach ($rows as $themesRaw) {
            // themes is stored as a space- or comma-separated string in Lichess CSV.
            $themes = preg_split('/[\s,]+/', (string) $themesRaw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($themes as $t) {
                if (! isset($counts[$t])) {
                    $counts[$t] = 0;
                }
                $counts[$t]++;
            }
        }

        arsort($counts);
        return array_slice(array_keys($counts), 0, 5);
    }

    /**
     * Common move-classification motifs from the user's recent (analyzed)
     * games — phase distribution of where blunders happen.
     */
    public function frequentBlunderMotifs(User $user): array
    {
        $rows = DB::table('move_analyses as ma')
            ->join('games as g', 'ma.game_id', '=', 'g.id')
            ->where('g.user_id', $user->id)
            ->whereRaw('ma.color = g.user_color')
            ->whereIn('ma.classification', ['blunder', 'mistake', 'miss'])
            ->orderByDesc('g.played_at')
            ->limit(60)
            ->select('ma.classification', 'ma.move_number')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $opening = 0;
        $middle = 0;
        $end = 0;
        foreach ($rows as $r) {
            $n = (int) $r->move_number;
            if ($n <= 12) $opening++;
            elseif ($n <= 35) $middle++;
            else $end++;
        }

        $out = [];
        if ($opening >= 4) $out[] = 'opening blunders';
        if ($middle >= 4) $out[] = 'middlegame blunders';
        if ($end >= 4) $out[] = 'endgame blunders';
        return $out;
    }

    /**
     * Returns the deviation ply for the given game, if any. Caller compares
     * against the move_number * 2 - (color === white ? 2 : 1) ply index to
     * decide whether THIS move is the deviation point.
     */
    public function repertoireDeviationPly(?int $gameId): ?int
    {
        if (! $gameId) {
            return null;
        }
        return Game::query()
            ->where('id', $gameId)
            ->value('repertoire_deviation_ply');
    }

    /**
     * Lichess Masters popularity for a position+played-move pair. Returns
     * percentage (0-100) of master games that played this exact move from
     * this FEN, or null if the data isn't available. Cached for 7 days
     * because masters DB is functionally static.
     */
    public function mastersPopularity(string $fenBefore, string $playedSan): ?float
    {
        $key = 'masters_pop:' . sha1($fenBefore . '|' . $playedSan);
        return Cache::remember($key, now()->addDays(7), function () use ($fenBefore, $playedSan) {
            try {
                $resp = Http::timeout(6)->get('https://explorer.lichess.ovh/masters', [
                    'fen' => $fenBefore,
                ]);
            } catch (\Throwable) {
                return null;
            }
            if (! $resp->successful()) {
                return null;
            }
            $data = $resp->json();
            $moves = $data['moves'] ?? [];
            if (empty($moves)) {
                return null;
            }
            $total = 0;
            $matched = null;
            foreach ($moves as $m) {
                $count = ((int) ($m['white'] ?? 0)) + ((int) ($m['draws'] ?? 0)) + ((int) ($m['black'] ?? 0));
                $total += $count;
                if (($m['san'] ?? null) === $playedSan) {
                    $matched = $count;
                }
            }
            if ($total === 0 || $matched === null) {
                return null;
            }
            return round(($matched / $total) * 100, 1);
        });
    }
}
