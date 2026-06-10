<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GAME_REVIEW_REFACTOR §5 — Phase 1 gate.
 *
 * Confirms the backend prompts the AI with a *different* persona block when
 * trainer_id is overridden in the request, and falls back to the user's
 * saved selection when not. Captures the Gemini system_instruction so the
 * frontend can be wired with confidence that distinct trainers produce
 * distinct prompts.
 */
class TrainerPersonaInjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_trainer_persona_resolves_override_then_selected_then_null(): void
    {
        $u = User::factory()->create(['selected_trainer_id' => 'king']);

        $kingPersona = (string) config('trainers.king.persona');
        $queenPersona = (string) config('trainers.queen.persona');

        $this->assertSame($kingPersona, $u->trainerPersona());
        $this->assertSame($queenPersona, $u->trainerPersona('queen'));
        $this->assertNotSame($kingPersona, $queenPersona);

        $fresh = User::factory()->create(['selected_trainer_id' => null]);
        $this->assertNull($fresh->trainerPersona());
        $this->assertSame($kingPersona, $fresh->trainerPersona('king'));
    }

    public function test_commentary_endpoint_sends_distinct_persona_per_trainer_override(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => 'fixed reply']]]],
                ],
            ], 200),
        ]);

        $user = User::factory()->create(['selected_trainer_id' => 'king']);
        Sanctum::actingAs($user);

        // Two requests with different trainer_id — backend should prepend a
        // different persona block to each Gemini call. We deliberately vary
        // the san so we sidestep the commentary cache (which is shared across
        // personas per the documented design: persona shapes tone, not facts).
        $this->postJson('/api/chess/commentary', [
            'san' => 'Qe4',
            'classification' => 'blunder',
            'cp_loss' => 320,
            'trainer_id' => 'queen',
        ])->assertOk();

        $this->postJson('/api/chess/commentary', [
            'san' => 'Qe5',
            'classification' => 'blunder',
            'cp_loss' => 320,
            'trainer_id' => 'rook',
        ])->assertOk();

        $captured = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0]->data())
            ->map(fn ($body) => $body['system_instruction']['parts'][0]['text'] ?? '')
            ->all();

        $this->assertCount(2, $captured);
        $this->assertStringContainsString((string) config('trainers.queen.persona'), $captured[0]);
        $this->assertStringContainsString((string) config('trainers.rook.persona'), $captured[1]);
        $this->assertNotSame($captured[0], $captured[1]);
    }

    public function test_commentary_endpoint_falls_back_to_users_selected_trainer(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'fixed reply']]]]],
            ], 200),
        ]);

        $user = User::factory()->create(['selected_trainer_id' => 'bishop']);
        Sanctum::actingAs($user);

        $this->postJson('/api/chess/commentary', [
            'san' => 'Nf3',
            'classification' => 'best',
        ])->assertOk();

        $sent = Http::recorded()[0][0]->data();
        $system = $sent['system_instruction']['parts'][0]['text'] ?? '';
        $this->assertStringContainsString((string) config('trainers.bishop.persona'), $system);
    }

    public function test_commentary_rejects_unknown_trainer_id(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/chess/commentary', [
            'san' => 'Nf3',
            'classification' => 'best',
            'trainer_id' => 'pawn',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['trainer_id']);
    }

    public function test_hint_endpoint_passes_trainer_persona_override(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'Look at the long diagonal.']]]]],
            ], 200),
        ]);

        $user = User::factory()->create(['selected_trainer_id' => 'king']);
        Sanctum::actingAs($user);

        $this->postJson('/api/chess/hint', [
            'fen' => 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1',
            'trainer_id' => 'knight',
        ])->assertOk();

        $sent = Http::recorded()[0][0]->data();
        $system = $sent['system_instruction']['parts'][0]['text'] ?? '';
        $this->assertStringContainsString((string) config('trainers.knight.persona'), $system);
        $this->assertStringNotContainsString((string) config('trainers.king.persona'), $system);
    }

    public function test_coach_summary_endpoint_passes_trainer_persona_override(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => '{"top_theme":"x","biggest_missed_idea":"y","drill_recommendation":"z"}']]]]],
            ], 200),
        ]);

        $user = User::factory()->create(['selected_trainer_id' => 'king']);
        Sanctum::actingAs($user);

        $game = Game::create([
            'user_id' => $user->id,
            'chess_com_game_id' => 'test-game-1',
            'pgn' => '1. e4 e5 2. Nf3 Nc6 3. Bc4 Bc5',
            'white_username' => 'tester',
            'black_username' => 'opponent',
            'user_color' => 'white',
            'result' => 'win',
            'opening_name' => 'Italian Game',
            'analyzed_at' => now(),
        ]);

        $this->postJson("/api/games/{$game->id}/coach-summary", [
            'trainer_id' => 'rook',
        ])->assertOk();

        $sent = Http::recorded()[0][0]->data();
        $system = $sent['system_instruction']['parts'][0]['text'] ?? '';
        $this->assertStringContainsString((string) config('trainers.rook.persona'), $system);
    }
}
