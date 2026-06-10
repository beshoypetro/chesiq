<?php

namespace Tests\Feature;

use App\Models\CoachSession;
use App\Models\Game;
use App\Models\MoveAnalysis;
use App\Models\Puzzle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Coverage for V3 Phase 2 (CHESSIQ_V3_PLAN §2):
 * - GET  /api/coach/session           server-composed daily session script
 * - POST /api/coach/session/complete  completion + streak
 */
class CoachSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_requires_auth(): void
    {
        $this->getJson('/api/coach/session')->assertUnauthorized();
    }

    public function test_empty_account_still_gets_a_valid_script(): void
    {
        Sanctum::actingAs(User::factory()->create(['name' => 'Beshoy Tester']));

        $res = $this->getJson('/api/coach/session')->assertOk();

        $steps = $res->json('steps');
        $types = array_column($steps, 'type');

        $this->assertSame('greeting', $types[0]);
        $this->assertSame('wrapup', end($types));
        $this->assertNotContains('quiz', $types, 'No analyzed games — no quiz step.');
        $this->assertStringContainsString('Beshoy', $steps[0]['say']);
        $this->assertSame(now()->toDateString(), $res->json('date'));
        $this->assertNull($res->json('completed_at'));
    }

    public function test_quiz_step_built_from_worst_mistake_of_latest_analyzed_game(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $game = Game::create([
            'user_id' => $user->id, 'chess_com_game_id' => 'g-quiz',
            'pgn' => '1. e4 e5 2. Nf3 Nc6 3. Bb5 a6',
            'white_username' => 'me', 'black_username' => 'them', 'user_color' => 'white',
            'result' => 'loss', 'opening_name' => 'Ruy Lopez', 'analyzed_at' => now(),
            'played_at' => now()->subHours(5),
        ]);
        MoveAnalysis::create([
            'game_id' => $game->id, 'move_number' => 2, 'color' => 'white',
            'move_san' => 'Nf3', 'best_move_san' => 'd4',
            'classification' => 'mistake', 'cp_loss' => 90,
        ]);
        MoveAnalysis::create([
            'game_id' => $game->id, 'move_number' => 3, 'color' => 'white',
            'move_san' => 'Bb5', 'best_move_san' => 'Nc3',
            'classification' => 'blunder', 'cp_loss' => 310,
        ]);
        // Opponent blunder must never become the user's quiz.
        MoveAnalysis::create([
            'game_id' => $game->id, 'move_number' => 3, 'color' => 'black',
            'move_san' => 'a6', 'best_move_san' => 'Nf6',
            'classification' => 'blunder', 'cp_loss' => 500,
        ]);

        $steps = $this->getJson('/api/coach/session')->assertOk()->json('steps');
        $quiz = collect($steps)->firstWhere('type', 'quiz');

        $this->assertNotNull($quiz);
        $this->assertSame('Bb5', $quiz['payload']['played_san']);
        $this->assertSame('Nc3', $quiz['payload']['best_san']);
        // White's move 3 is ply 5.
        $this->assertSame(5, $quiz['payload']['ply']);
        $this->assertSame($game->id, $quiz['payload']['game_id']);
        $this->assertStringContainsString('bishop to b5', $quiz['say']);
        $this->assertStringNotContainsString('Bb5', $quiz['say'], 'Say lines speak SAN, never read it raw.');
    }

    public function test_puzzle_steps_come_from_homework_set(): void
    {
        Sanctum::actingAs(User::factory()->create());

        foreach (range(1, 4) as $i) {
            Puzzle::create([
                'id' => "pz{$i}",
                'fen' => '6k1/5ppp/8/8/8/8/5PPP/3R2K1 w - - 0 1',
                'moves' => 'd1d8 g8h7',
                'rating' => 1200,
                'themes' => 'backRankMate',
            ]);
        }

        $steps = $this->getJson('/api/coach/session')->assertOk()->json('steps');
        $puzzles = collect($steps)->where('type', 'puzzle');

        $this->assertGreaterThanOrEqual(1, $puzzles->count());
        $this->assertLessThanOrEqual(3, $puzzles->count());
        $first = $puzzles->first();
        $this->assertArrayHasKey('fen', $first['payload']);
        $this->assertArrayHasKey('moves', $first['payload']);
    }

    public function test_script_is_persisted_and_stable_within_the_day(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $first = $this->getJson('/api/coach/session')->assertOk()->json('steps');
        $second = $this->getJson('/api/coach/session')->assertOk()->json('steps');

        $this->assertSame($first, $second);
        $this->assertSame(1, CoachSession::count());
    }

    public function test_complete_marks_session_and_counts_streak(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // Yesterday's completed session feeds the streak.
        CoachSession::create([
            'user_id' => $user->id,
            'date' => now()->subDay()->toDateString(),
            'completed_at' => now()->subDay(),
            'steps_json' => [['type' => 'greeting', 'say' => 'hi']],
        ]);

        $this->getJson('/api/coach/session')->assertOk();

        $res = $this->postJson('/api/coach/session/complete')->assertOk();
        $this->assertNotNull($res->json('completed_at'));
        $this->assertSame(2, $res->json('streak'));
    }

    public function test_complete_without_todays_session_404s(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/coach/session/complete')->assertNotFound();
    }
}
