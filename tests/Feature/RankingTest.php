<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\ImprovementPlan;
use App\Models\User;
use App\Models\UserAssessment;
use App\Services\ImprovementPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ranking advancement backend — GET /api/ranking + improvement-plan ranking block.
 *
 * Tests:
 *   1. series contains the user's own rating (correct color extraction)
 *   2. positive delta when ratings rise
 *   3. by_time_class maps correctly
 *   4. user with no games → HTTP 200, empty series, null delta
 *   5. improvement-plan payload includes ranking block with correct elo_delta
 *   6. period filter limits series
 *   7. time_class query param filters series
 */
class RankingTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'selected_trainer_id' => 'king',
            'placement_elo'       => 1200,
            'placement_track'     => 'improver',
            'placement_completed_at' => now(),
        ], $overrides));
    }

    private function seedAssessment(User $user): void
    {
        UserAssessment::create([
            'user_id'              => $user->id,
            'started_at'           => now()->subMinutes(5),
            'completed_at'         => now(),
            'current_elo_estimate' => $user->placement_elo,
            'confidence_interval'  => 50,
            'final_elo_estimate'   => $user->placement_elo,
            'weakness_profile_json' => ['tactics' => ['accuracy' => 0.7, 'count' => 4]],
            'recommended_track'    => 'improver',
        ]);
    }

    /**
     * Insert a game row for $user with an explicit user-side rating.
     */
    private function makeGame(User $user, array $attrs = []): Game
    {
        static $counter = 0;
        $counter++;

        $color  = $attrs['user_color'] ?? 'white';
        $rating = $attrs['rating'] ?? 1200;

        return Game::create(array_merge([
            'user_id'           => $user->id,
            'chess_com_game_id' => 'game-'.$user->id.'-'.$counter,
            'pgn'               => '1. e4 e5',
            'white_username'    => $color === 'white' ? 'me' : 'opp',
            'black_username'    => $color === 'black' ? 'me' : 'opp',
            'white_rating'      => $color === 'white' ? $rating : ($attrs['opp_rating'] ?? 1100),
            'black_rating'      => $color === 'black' ? $rating : ($attrs['opp_rating'] ?? 1100),
            'user_color'        => $color,
            'result'            => 'win',
            'time_class'        => $attrs['time_class'] ?? 'rapid',
            'played_at'         => $attrs['played_at'] ?? now(),
        ], array_diff_key($attrs, array_flip(['rating', 'opp_rating']))));
    }

    // ------------------------------------------------------------------
    // 1. Series contains the user's own rating (correct color extraction)
    // ------------------------------------------------------------------

    public function test_series_extracts_correct_color_rating(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        // white game: user is white, white_rating=1150
        $this->makeGame($user, [
            'user_color'  => 'white',
            'rating'      => 1150,
            'opp_rating'  => 1080,
            'played_at'   => now()->subDays(5),
            'time_class'  => 'rapid',
        ]);

        // black game: user is black, black_rating=1200
        $this->makeGame($user, [
            'user_color'  => 'black',
            'rating'      => 1200,
            'opp_rating'  => 1100,
            'played_at'   => now()->subDays(2),
            'time_class'  => 'rapid',
        ]);

        $res = $this->getJson('/api/ranking')->assertOk();

        $series = $res->json('series');
        $this->assertCount(2, $series);

        // first game (oldest) → user was white → rating 1150
        $this->assertSame(1150, $series[0]['rating']);
        // second game → user was black → rating 1200
        $this->assertSame(1200, $series[1]['rating']);
    }

    // ------------------------------------------------------------------
    // 2. Positive delta when ratings rise
    // ------------------------------------------------------------------

    public function test_delta_is_positive_when_ratings_rise(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->makeGame($user, ['rating' => 1100, 'played_at' => now()->subDays(10)]);
        $this->makeGame($user, ['rating' => 1150, 'played_at' => now()->subDays(5)]);
        $this->makeGame($user, ['rating' => 1200, 'played_at' => now()->subDays(1)]);

        $res = $this->getJson('/api/ranking')->assertOk();

        $this->assertSame(1100, $res->json('start'));
        $this->assertSame(1200, $res->json('current'));
        $this->assertSame(100,  $res->json('delta'));
        $this->assertSame(3,    $res->json('games_counted'));
    }

    // ------------------------------------------------------------------
    // 3. by_time_class maps latest rating per time_class
    // ------------------------------------------------------------------

    public function test_by_time_class_returns_latest_per_class(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->makeGame($user, ['rating' => 1050, 'time_class' => 'rapid',  'played_at' => now()->subDays(8)]);
        $this->makeGame($user, ['rating' => 1100, 'time_class' => 'rapid',  'played_at' => now()->subDays(4)]);
        $this->makeGame($user, ['rating' => 900,  'time_class' => 'blitz',  'played_at' => now()->subDays(6)]);
        $this->makeGame($user, ['rating' => 950,  'time_class' => 'blitz',  'played_at' => now()->subDays(2)]);

        $res = $this->getJson('/api/ranking')->assertOk();

        $byClass = $res->json('by_time_class');
        $this->assertSame(1100, $byClass['rapid']);
        $this->assertSame(950,  $byClass['blitz']);
    }

    // ------------------------------------------------------------------
    // 4. User with no games → HTTP 200 with empty series and null delta
    // ------------------------------------------------------------------

    public function test_no_games_returns_200_with_empty_series(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $res = $this->getJson('/api/ranking')->assertOk();

        $this->assertSame([],  $res->json('series'));
        $this->assertNull($res->json('current'));
        $this->assertNull($res->json('delta'));
        $this->assertSame(0, $res->json('games_counted'));
    }

    // ------------------------------------------------------------------
    // 5. Improvement-plan payload includes ranking block with elo_delta
    // ------------------------------------------------------------------

    public function test_improvement_plan_payload_includes_ranking_block_with_elo_delta(): void
    {
        $user = $this->makeUser(['placement_elo' => 1200]);
        $this->seedAssessment($user);
        Sanctum::actingAs($user);

        // Create the plan so placement_elo_at_creation is set (the service writes it).
        $plan = app(ImprovementPlanService::class)->sync($user);
        $this->assertSame(1200, $plan->placement_elo_at_creation);

        // Add a game with a HIGHER rating → delta should be positive.
        $this->makeGame($user, [
            'rating'     => 1250,
            'played_at'  => now(),
            'time_class' => 'rapid',
        ]);

        $res = $this->getJson('/api/improvement-plan')->assertOk();

        $res->assertJsonStructure([
            'plan' => [
                'ranking' => ['placement_elo_at_creation', 'current_elo', 'elo_delta'],
            ],
        ]);

        $ranking = $res->json('plan.ranking');
        $this->assertSame(1200, $ranking['placement_elo_at_creation']);
        $this->assertSame(1250, $ranking['current_elo']);
        $this->assertSame(50,   $ranking['elo_delta']);
    }

    // ------------------------------------------------------------------
    // 6. Improvement-plan ranking block nulls out gracefully with no games
    // ------------------------------------------------------------------

    public function test_improvement_plan_ranking_block_uses_placement_elo_fallback_with_no_games(): void
    {
        $user = $this->makeUser(['placement_elo' => 1100]);
        $this->seedAssessment($user);
        Sanctum::actingAs($user);

        app(ImprovementPlanService::class)->sync($user);

        $res = $this->getJson('/api/improvement-plan')->assertOk();

        $ranking = $res->json('plan.ranking');
        // No games → current_elo falls back to placement_elo
        $this->assertSame(1100, $ranking['current_elo']);
        // delta = current - creation_snapshot = 1100 - 1100 = 0
        $this->assertSame(0, $ranking['elo_delta']);
    }

    // ------------------------------------------------------------------
    // 7. period filter limits series to the requested window
    // ------------------------------------------------------------------

    public function test_period_filter_limits_series_to_window(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        // One old game (outside 30d) and one recent game (inside 30d).
        $this->makeGame($user, ['rating' => 1000, 'played_at' => now()->subDays(60)]);
        $this->makeGame($user, ['rating' => 1100, 'played_at' => now()->subDays(5)]);

        $res = $this->getJson('/api/ranking?period=30d')->assertOk();

        $series = $res->json('series');
        $this->assertCount(1, $series);
        $this->assertSame(1100, $series[0]['rating']);
    }

    // ------------------------------------------------------------------
    // 8. time_class query param filters series
    // ------------------------------------------------------------------

    public function test_time_class_filter_returns_only_matching_games(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->makeGame($user, ['rating' => 1050, 'time_class' => 'rapid', 'played_at' => now()->subDays(3)]);
        $this->makeGame($user, ['rating' => 900,  'time_class' => 'blitz', 'played_at' => now()->subDays(2)]);
        $this->makeGame($user, ['rating' => 1060, 'time_class' => 'rapid', 'played_at' => now()->subDays(1)]);

        $res = $this->getJson('/api/ranking?time_class=rapid')->assertOk();

        $series = $res->json('series');
        $this->assertCount(2, $series);
        foreach ($series as $point) {
            $this->assertSame('rapid', $point['time_class']);
        }
    }
}
