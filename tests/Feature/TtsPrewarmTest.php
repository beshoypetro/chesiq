<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PiperTtsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Coverage for V3 Phase 1 (CHESSIQ_V3_PLAN §1):
 * - POST /api/tts/prewarm  pre-synthesizes upcoming lines into the Piper cache
 */
class TtsPrewarmTest extends TestCase
{
    use RefreshDatabase;

    public function test_prewarm_requires_auth(): void
    {
        $this->postJson('/api/tts/prewarm', ['texts' => ['Hello.']])
            ->assertUnauthorized();
    }

    public function test_prewarm_synthesizes_each_text_and_reports_count(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->mock(PiperTtsService::class, function ($mock) {
            $mock->shouldReceive('synthesize')
                ->twice()
                ->andReturn('RIFF-fake-wav-bytes');
        });

        $this->postJson('/api/tts/prewarm', [
            'texts' => ['Welcome back. Ready to work?', 'Now, a quick exercise.'],
            'voice' => 'en_US-amy-medium',
        ])
            ->assertOk()
            ->assertJson(['warmed' => 2]);
    }

    public function test_prewarm_caps_batch_size_at_three(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/tts/prewarm', [
            'texts' => ['one', 'two', 'three', 'four'],
        ])->assertStatus(422);
    }

    public function test_prewarm_rejects_long_texts(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/tts/prewarm', [
            'texts' => [str_repeat('a', 401)],
        ])->assertStatus(422);
    }

    public function test_prewarm_counts_against_daily_cap_and_429s_when_exhausted(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        Cache::put('tts_daily:'.$user->id.':'.now()->format('Y-m-d'), 2000, now()->endOfDay());

        $this->postJson('/api/tts/prewarm', ['texts' => ['Hello.']])
            ->assertStatus(429)
            ->assertJson(['warmed' => 0]);
    }

    public function test_prewarm_reports_zero_when_piper_unavailable(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->mock(PiperTtsService::class, function ($mock) {
            $mock->shouldReceive('synthesize')->once()->andReturnNull();
        });

        $this->postJson('/api/tts/prewarm', ['texts' => ['Hello.']])
            ->assertOk()
            ->assertJson(['warmed' => 0]);
    }
}
