<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class AchievementService
{
    private const ACHIEVEMENTS = [
        // Puzzles
        ['id' => 'puzzle_first',     'name' => 'First Solve',         'description' => 'Solve your first puzzle',                'category' => 'puzzles',  'icon' => '🧩'],
        ['id' => 'puzzle_10',        'name' => 'Puzzle Apprentice',   'description' => 'Solve 10 puzzles',                      'category' => 'puzzles',  'icon' => '⭐'],
        ['id' => 'puzzle_50',        'name' => 'Puzzle Practitioner', 'description' => 'Solve 50 puzzles',                      'category' => 'puzzles',  'icon' => '🌟'],
        ['id' => 'puzzle_100',       'name' => 'Puzzle Master',       'description' => 'Solve 100 puzzles',                     'category' => 'puzzles',  'icon' => '💎'],
        ['id' => 'puzzle_500',       'name' => 'Puzzle Legend',       'description' => 'Solve 500 puzzles',                     'category' => 'puzzles',  'icon' => '👑'],
        ['id' => 'puzzle_rated_1600','name' => 'Sharp Tactician',     'description' => 'Reach a puzzle rating of 1600',         'category' => 'puzzles',  'icon' => '⚡'],
        ['id' => 'puzzle_rated_1800','name' => 'Tactical Expert',     'description' => 'Reach a puzzle rating of 1800',         'category' => 'puzzles',  'icon' => '🔥'],
        // Streaks
        ['id' => 'streak_3',         'name' => 'On a Roll',           'description' => '3-day login streak',                    'category' => 'streaks',  'icon' => '🔥'],
        ['id' => 'streak_7',         'name' => 'Dedicated Trainer',   'description' => '7-day login streak',                    'category' => 'streaks',  'icon' => '📅'],
        ['id' => 'streak_30',        'name' => 'Iron Discipline',     'description' => '30-day login streak',                   'category' => 'streaks',  'icon' => '🏆'],
        // Games
        ['id' => 'game_first',       'name' => 'Welcome Player',      'description' => 'Import your first game',                'category' => 'games',    'icon' => '♟'],
        ['id' => 'game_10',          'name' => 'Game Collector',      'description' => 'Import 10 games',                      'category' => 'games',    'icon' => '📚'],
        ['id' => 'game_50',          'name' => 'Veteran Player',      'description' => 'Import 50 games',                      'category' => 'games',    'icon' => '🎖'],
        ['id' => 'game_analyzed_5',  'name' => 'Self-Analyst',        'description' => 'Analyze 5 games',                      'category' => 'games',    'icon' => '🔍'],
        ['id' => 'game_accuracy_90', 'name' => 'Near Perfect',        'description' => 'Achieve 90%+ accuracy in a game',       'category' => 'games',    'icon' => '💯'],
        // Analysis
        ['id' => 'no_blunders',      'name' => 'Clean Game',          'description' => 'Complete a game with zero blunders',    'category' => 'analysis', 'icon' => '✨'],
        ['id' => 'brilliant_move',   'name' => 'Brilliant!',          'description' => 'Achieve a brilliant move in a game',    'category' => 'analysis', 'icon' => '!!'],
        ['id' => 'endgame_5',        'name' => 'Endgame Technician',  'description' => 'Complete 5 endgame trainer positions',  'category' => 'analysis', 'icon' => '♔'],
        ['id' => 'streak_mode_10',   'name' => 'Streak Hunter',       'description' => 'Reach a streak of 10 in Streak Mode',   'category' => 'puzzles',  'icon' => '🎯'],
        ['id' => 'rush_mode_20',     'name' => 'Speed Solver',        'description' => 'Score 20+ in Puzzle Rush',              'category' => 'puzzles',  'icon' => '⏱'],
    ];

    /**
     * Seed all achievement definitions into the DB.
     */
    public function seed(): void
    {
        foreach (self::ACHIEVEMENTS as $ach) {
            DB::table('achievements')->updateOrInsert(
                ['id' => $ach['id']],
                $ach
            );
        }
    }

    /**
     * Check and award any newly earned achievements for the user.
     * Returns array of newly earned achievement IDs.
     */
    public function check(User $user): array
    {
        $this->seed();

        $already = DB::table('user_achievements')
            ->where('user_id', $user->id)
            ->pluck('achievement_id')
            ->toArray();

        $puzzlesSolved = DB::table('user_puzzle_attempts')
            ->where('user_id', $user->id)
            ->where('solved', true)
            ->count();

        $puzzleRating = DB::table('user_puzzle_ratings')
            ->where('user_id', $user->id)
            ->value('rating') ?? 0;

        $totalGames = $user->games()->count();
        $analyzedGames = $user->games()->whereNotNull('analyzed_at')->count();

        $streakLength = DB::table('puzzle_streak_scores')
            ->where('user_id', $user->id)
            ->max('length') ?? 0;

        $rushBest = DB::table('puzzle_rush_scores')
            ->where('user_id', $user->id)
            ->max('score') ?? 0;

        $endgameAttempts = DB::table('user_endgame_attempts')
            ->where('user_id', $user->id)
            ->where('correct', true)
            ->count();

        $hasBrilliant = DB::table('move_analyses as ma')
            ->join('games as g', 'ma.game_id', '=', 'g.id')
            ->where('g.user_id', $user->id)
            ->where('ma.classification', 'brilliant')
            ->exists();

        $highAccGame = $user->games()
            ->whereNotNull('analyzed_at')
            ->get()
            ->first(fn ($g) => ($g->user_color === 'white' ? $g->white_accuracy : $g->black_accuracy) >= 90);

        $cleanGame = $user->games()
            ->whereNotNull('analyzed_at')
            ->get()
            ->filter(function ($g) {
                $blunders = DB::table('move_analyses')
                    ->where('game_id', $g->id)
                    ->where('color', $g->user_color)
                    ->whereIn('classification', ['blunder', 'miss'])
                    ->count();
                return $blunders === 0;
            })->isNotEmpty();

        $conditions = [
            'puzzle_first'      => $puzzlesSolved >= 1,
            'puzzle_10'         => $puzzlesSolved >= 10,
            'puzzle_50'         => $puzzlesSolved >= 50,
            'puzzle_100'        => $puzzlesSolved >= 100,
            'puzzle_500'        => $puzzlesSolved >= 500,
            'puzzle_rated_1600' => $puzzleRating >= 1600,
            'puzzle_rated_1800' => $puzzleRating >= 1800,
            'streak_3'          => ($user->current_streak ?? 0) >= 3,
            'streak_7'          => ($user->current_streak ?? 0) >= 7,
            'streak_30'         => ($user->current_streak ?? 0) >= 30,
            'game_first'        => $totalGames >= 1,
            'game_10'           => $totalGames >= 10,
            'game_50'           => $totalGames >= 50,
            'game_analyzed_5'   => $analyzedGames >= 5,
            'game_accuracy_90'  => !! $highAccGame,
            'no_blunders'       => $cleanGame,
            'brilliant_move'    => $hasBrilliant,
            'endgame_5'         => $endgameAttempts >= 5,
            'streak_mode_10'    => $streakLength >= 10,
            'rush_mode_20'      => $rushBest >= 20,
        ];

        $newlyEarned = [];
        foreach ($conditions as $id => $earned) {
            if ($earned && ! in_array($id, $already)) {
                DB::table('user_achievements')->insert([
                    'user_id' => $user->id,
                    'achievement_id' => $id,
                    'earned_at' => now(),
                ]);
                $newlyEarned[] = $id;
            }
        }

        return $newlyEarned;
    }

    /**
     * Update daily login streak for user.
     */
    public function updateStreak(User $user): void
    {
        $today = now()->toDateString();
        $lastActive = $user->last_active_date;

        if ($lastActive === $today) {
            return; // Already updated today
        }

        $yesterday = now()->subDay()->toDateString();
        $newStreak = ($lastActive === $yesterday) ? ($user->current_streak ?? 0) + 1 : 1;

        $user->update([
            'current_streak' => $newStreak,
            'last_active_date' => $today,
        ]);
    }
}
