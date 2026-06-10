<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Settings-page preferences:
 * - GET  /api/me                 includes a `preferences` block with defaults
 * - PATCH /api/user/preferences  persists toggles; email maps to digest opt-out
 */
class PreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_returns_preference_defaults(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.preferences.email_notifications', true)
            ->assertJsonPath('user.preferences.auto_analyze', true)
            ->assertJsonPath('user.preferences.public_profile', false);
    }

    public function test_update_preferences_requires_auth(): void
    {
        $this->patchJson('/api/user/preferences', ['auto_analyze' => false])
            ->assertUnauthorized();
    }

    public function test_update_preferences_persists_toggles(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/user/preferences', [
            'auto_analyze' => false,
            'public_profile' => true,
        ])
            ->assertOk()
            ->assertJsonPath('user.preferences.auto_analyze', false)
            ->assertJsonPath('user.preferences.public_profile', true);

        $fresh = $user->fresh();
        $this->assertFalse($fresh->resolvedPreferences()['auto_analyze']);
        $this->assertTrue($fresh->resolvedPreferences()['public_profile']);
    }

    public function test_email_notifications_toggle_maps_to_digest_opt_out(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/user/preferences', ['email_notifications' => false])
            ->assertOk()
            ->assertJsonPath('user.preferences.email_notifications', false);

        $this->assertNotNull($user->fresh()->digest_unsubscribed_at);

        // Re-enabling clears the opt-out.
        $this->patchJson('/api/user/preferences', ['email_notifications' => true])
            ->assertOk()
            ->assertJsonPath('user.preferences.email_notifications', true);

        $this->assertNull($user->fresh()->digest_unsubscribed_at);
    }

    public function test_daily_review_includes_unified_review_queue(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $res = $this->getJson('/api/daily-review')->assertOk();

        // Daily tactics is always offered as the floor of the queue.
        $kinds = array_map(fn ($b) => $b['kind'], $res->json('review_queue'));
        $this->assertContains('tactics', $kinds);
        $res->assertJsonPath('review_due_total', 0);
    }
}
