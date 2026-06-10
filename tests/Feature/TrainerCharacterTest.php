<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Coverage for V2 Phase B (CHESSIQ_V2_PLAN §28):
 * - GET  /api/trainers           public list
 * - PATCH /api/user/trainer      auth-guarded, validates against config
 * - GET  /api/me                 returns the new V2 fields
 */
class TrainerCharacterTest extends TestCase
{
    use RefreshDatabase;

    public function test_trainers_index_is_public_and_returns_five(): void
    {
        $res = $this->getJson('/api/trainers');

        $res->assertOk();
        $res->assertJsonCount(5, 'trainers');
        $res->assertJsonStructure([
            'trainers' => [
                ['id', 'name', 'piece', 'voice_model', 'default_mode', 'specialty', 'tagline'],
            ],
        ]);

        // Make sure persona is NOT leaked publicly — it's a server-side LLM detail.
        $body = $res->json('trainers');
        foreach ($body as $t) {
            $this->assertArrayNotHasKey('persona', $t);
        }

        $ids = array_map(fn ($t) => $t['id'], $body);
        $this->assertSame(['king', 'queen', 'knight', 'bishop', 'rook'], $ids);
    }

    public function test_select_trainer_requires_auth(): void
    {
        $this->patchJson('/api/user/trainer', ['trainer_id' => 'king'])
            ->assertUnauthorized();
    }

    public function test_select_trainer_validates_id_against_config(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/user/trainer', ['trainer_id' => 'pawn'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['trainer_id']);

        $this->assertNull($user->fresh()->selected_trainer_id);
    }

    public function test_select_trainer_persists_choice(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $res = $this->patchJson('/api/user/trainer', ['trainer_id' => 'knight']);

        $res->assertOk();
        $res->assertJson(['selected_trainer_id' => 'knight']);
        $this->assertSame('knight', $user->fresh()->selected_trainer_id);
    }

    public function test_me_returns_v2_onboarding_fields(): void
    {
        $user = User::factory()->create([
            'selected_trainer_id' => 'queen',
            'coaching_mode' => 'tour',
            'onboarding_step' => 3,
            'intents' => ['sharpen_tactics', 'just_play_more'],
            'lichess_username' => 'magnus_jr',
        ]);
        Sanctum::actingAs($user);

        $res = $this->getJson('/api/me');

        $res->assertOk();
        $res->assertJson([
            'user' => [
                'selected_trainer_id' => 'queen',
                'coaching_mode' => 'tour',
                'onboarding_step' => 3,
                'intents' => ['sharpen_tactics', 'just_play_more'],
                'lichess_username' => 'magnus_jr',
            ],
        ]);
    }

    public function test_me_defaults_for_fresh_user(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $res = $this->getJson('/api/me')->assertOk();
        $res->assertJson([
            'user' => [
                'selected_trainer_id' => null,
                'coaching_mode' => 'full',
                'onboarding_step' => 0,
                'onboarding_completed_at' => null,
                'intents' => [],
                'lichess_username' => null,
            ],
        ]);
    }

    public function test_update_intent_saves_array(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/user/intent', [
            'intents' => ['build_openings', 'take_lessons'],
        ])->assertOk();

        $this->assertSame(['build_openings', 'take_lessons'], $user->fresh()->intents);
    }

    public function test_update_intent_rejects_unknown_values(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/user/intent', [
            'intents' => ['build_openings', 'become_magnus'],
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['intents.1']);
    }

    public function test_update_coaching_mode(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/user/coaching-mode', ['coaching_mode' => 'tour'])
            ->assertOk();
        $this->assertSame('tour', $user->fresh()->coaching_mode);

        $this->patchJson('/api/user/coaching-mode', ['coaching_mode' => 'invalid'])
            ->assertStatus(422);
    }

    public function test_onboarding_status_and_skip_advance(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/user/onboarding')
            ->assertOk()
            ->assertJson([
                'onboarding_step' => 0,
                'has_chess_com' => false,
                'has_intents' => false,
            ]);

        $this->postJson('/api/user/onboarding/skip', ['step' => 3])
            ->assertOk()
            ->assertJson(['onboarding_step' => 3]);

        // Skipping to a smaller step never regresses.
        $this->postJson('/api/user/onboarding/skip', ['step' => 1])
            ->assertOk()
            ->assertJson(['onboarding_step' => 3]);

        // Completing onboarding stamps the timestamp.
        $this->postJson('/api/user/onboarding/skip', ['step' => 6])
            ->assertOk();
        $this->assertNotNull($user->fresh()->onboarding_completed_at);
    }
}
