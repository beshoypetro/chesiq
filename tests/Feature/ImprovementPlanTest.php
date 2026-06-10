<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Course;
use App\Models\Game;
use App\Models\ImprovementPlan;
use App\Models\Module;
use App\Models\Track;
use App\Models\User;
use App\Models\UserAssessment;
use App\Services\ImprovementPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * IMPROVEMENT_PLAN_SPEC.md §13 "Backend" test gate.
 *
 * Covers: plan generation from placement_elo + weakness_profile, milestone
 * progress math, next-action selection (weakest needed skill), trainer-persona
 * personalization, and the four adaptivity mutation cases (§7).
 */
class ImprovementPlanTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'selected_trainer_id' => 'king',
            'placement_elo' => 1200,
            'placement_track' => 'improver',
            'placement_completed_at' => now(),
        ], $overrides));
    }

    /** A completed assessment with a per-theme weakness profile. */
    private function seedAssessment(User $user, array $profile): void
    {
        UserAssessment::create([
            'user_id' => $user->id,
            'started_at' => now()->subMinutes(10),
            'completed_at' => now(),
            'current_elo_estimate' => $user->placement_elo,
            'confidence_interval' => 50,
            'final_elo_estimate' => $user->placement_elo,
            'weakness_profile_json' => $profile,
            'recommended_track' => $user->placement_track,
        ]);
    }

    private function solvePuzzles(User $user, int $count, bool $solved = true): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('user_puzzle_attempts')->insert([
                'user_id' => $user->id,
                'puzzle_id' => 'p'.$user->id.'_'.$i.'_'.uniqid(),
                'solved' => $solved,
                'time_ms' => 5000,
                'created_at' => now(),
            ]);
        }
    }

    private function service(): ImprovementPlanService
    {
        return app(ImprovementPlanService::class);
    }

    // -----------------------------------------------------------------
    // Generation + level→plan link (Cat 1/3)
    // -----------------------------------------------------------------

    public function test_sync_generates_plan_from_placement_elo_and_weakness_profile(): void
    {
        $user = $this->makeUser(['placement_elo' => 1320]);
        $this->seedAssessment($user, [
            'tactics' => ['accuracy' => 0.80, 'count' => 4],
            'calculation' => ['accuracy' => 0.55, 'count' => 3],
            'strategy' => ['accuracy' => 0.60, 'count' => 3],
            'endgame' => ['accuracy' => 0.30, 'count' => 2],
        ]);

        $plan = $this->service()->sync($user);

        $this->assertInstanceOf(ImprovementPlan::class, $plan);
        $this->assertSame(1320, $plan->placement_elo_at_creation);

        // 1320 Elo → Improver band is current.
        $this->assertSame('improver', $plan->current_milestone_slug);

        // All four named milestones present, in order.
        $slugs = array_column($plan->milestones_json, 'slug');
        $this->assertSame(['foundations', 'improver', 'club', 'tournament'], $slugs);

        // Below-band done, band current, above locked.
        $byslug = collect($plan->milestones_json)->keyBy('slug');
        $this->assertSame('done', $byslug['foundations']['status']);
        $this->assertSame('current', $byslug['improver']['status']);
        $this->assertSame('locked', $byslug['club']['status']);
        $this->assertSame('locked', $byslug['tournament']['status']);

        // All five mastery dimensions computed.
        $this->assertSame(
            ['tactics', 'calculation', 'strategy', 'endgame', 'openings'],
            array_keys($plan->skill_mastery_json),
        );

        // Tactics (strong) should out-rank endgame (weak) in the profile.
        $this->assertGreaterThan(
            $plan->skill_mastery_json['endgame']['score'],
            $plan->skill_mastery_json['tactics']['score'],
        );

        // Readiness is a 0..100 composite.
        $this->assertGreaterThanOrEqual(0, $plan->readiness_score);
        $this->assertLessThanOrEqual(100, $plan->readiness_score);
    }

    public function test_show_returns_placement_incomplete_when_no_placement(): void
    {
        $user = User::factory()->create([
            'placement_elo' => null,
            'placement_completed_at' => null,
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/improvement-plan')
            ->assertOk()
            ->assertJsonPath('plan.placement_completed', false);
    }

    public function test_show_endpoint_returns_full_payload_shape(): void
    {
        $user = $this->makeUser();
        $this->seedAssessment($user, ['tactics' => ['accuracy' => 0.7, 'count' => 4]]);
        Sanctum::actingAs($user);

        $this->getJson('/api/improvement-plan')
            ->assertOk()
            ->assertJsonPath('plan.placement_completed', true)
            ->assertJsonStructure([
                'plan' => [
                    'readiness_score',
                    'trainer' => ['id', 'name', 'intro'],
                    'current_milestone',
                    'milestones',
                    'skill_mastery',
                    'next_action' => ['kind', 'label', 'rationale', 'route', 'skill', 'trainer_line'],
                    'this_week',
                    'adaptation_log',
                    'generated_at',
                    'last_synced_at',
                ],
            ]);
    }

    // -----------------------------------------------------------------
    // Milestone progress math (Cat 3)
    // -----------------------------------------------------------------

    public function test_milestone_progress_is_mean_of_criteria_pct(): void
    {
        $user = $this->makeUser(['placement_elo' => 1250]);
        $this->seedAssessment($user, ['tactics' => ['accuracy' => 0.6, 'count' => 4]]);

        $plan = $this->service()->sync($user);
        $current = collect($plan->milestones_json)->firstWhere('slug', 'improver');

        $pcts = array_column($current['criteria'], 'pct');
        $expected = (int) round(array_sum($pcts) / count($pcts));

        $this->assertSame($expected, $current['progress_pct']);

        // Rating criterion math: 1250 toward 1500 from floor 1000 = 50%.
        $rating = collect($current['criteria'])->firstWhere('key', 'rating');
        $this->assertSame(1500, $rating['target']);
        $this->assertSame(50, $rating['pct']);

        // Done milestones are pinned to 100%.
        $foundations = collect($plan->milestones_json)->firstWhere('slug', 'foundations');
        $this->assertSame(100, $foundations['progress_pct']);
    }

    // -----------------------------------------------------------------
    // Next-action selection (Cat 2)
    // -----------------------------------------------------------------

    public function test_next_action_targets_weakest_needed_skill(): void
    {
        // Improver band needs tactics + endgame. Make endgame the clear weakest.
        $user = $this->makeUser(['placement_elo' => 1200]);
        $this->seedAssessment($user, [
            'tactics' => ['accuracy' => 0.85, 'count' => 4],
            'endgame' => ['accuracy' => 0.10, 'count' => 2],
        ]);

        $plan = $this->service()->sync($user);

        $this->assertSame('endgame', $plan->next_action_json['skill']);
        $this->assertSame('/endgame', $plan->next_action_json['route']);
    }

    public function test_next_action_prefers_academy_activity_when_available(): void
    {
        $user = $this->makeUser(['placement_elo' => 1200]);
        $this->seedAssessment($user, ['tactics' => ['accuracy' => 0.2, 'count' => 4]]);

        $track = Track::create([
            'slug' => 'improver', 'name' => 'Improver', 'elo_min' => 1000, 'elo_max' => 1499,
            'display_order' => 1, 'published' => true,
        ]);
        $course = Course::create([
            'track_id' => $track->id, 'slug' => 'basics', 'name' => 'Basics', 'display_order' => 0,
        ]);
        $module = Module::create([
            'course_id' => $course->id, 'slug' => 'm1', 'name' => 'Module 1', 'display_order' => 0, 'published' => true,
        ]);
        Activity::create([
            'module_id' => $module->id, 'type' => 'puzzle_set', 'title' => 'Pins & Forks',
            'config' => [], 'display_order' => 0, 'published' => true,
        ]);

        $plan = $this->service()->sync($user);

        $this->assertSame('academy_activity', $plan->next_action_json['kind']);
        $this->assertSame('Pins & Forks', $plan->next_action_json['label']);
        $this->assertStringContainsString('/academy/module/'.$module->id, $plan->next_action_json['route']);
    }

    public function test_next_academy_activity_respects_curriculum_display_order(): void
    {
        // tactics is the weakest needed skill, but neither activity is a puzzle_set,
        // so no skill bias fires — ordering is purely by curriculum.
        $user = $this->makeUser(['placement_elo' => 1200]);
        $this->seedAssessment($user, [
            'tactics' => ['accuracy' => 0.20, 'count' => 6],
            'endgame' => ['accuracy' => 0.85, 'count' => 4],
        ]);

        $track = Track::create([
            'slug' => 'improver', 'name' => 'Improver', 'elo_min' => 1000, 'elo_max' => 1499,
            'display_order' => 1, 'published' => true,
        ]);
        $course = Course::create([
            'track_id' => $track->id, 'slug' => 'basics', 'name' => 'Basics', 'display_order' => 0,
        ]);

        // Create the LATER module first (lower id) to prove ordering is by
        // display_order, not insertion id — the bug the old code had.
        $second = Module::create([
            'course_id' => $course->id, 'slug' => 'm2', 'name' => 'Second', 'display_order' => 1, 'published' => true,
        ]);
        Activity::create([
            'module_id' => $second->id, 'type' => 'quiz', 'title' => 'Second Activity',
            'config' => [], 'display_order' => 0, 'published' => true,
        ]);
        $first = Module::create([
            'course_id' => $course->id, 'slug' => 'm1', 'name' => 'First', 'display_order' => 0, 'published' => true,
        ]);
        Activity::create([
            'module_id' => $first->id, 'type' => 'quiz', 'title' => 'First Activity',
            'config' => [], 'display_order' => 0, 'published' => true,
        ]);

        $plan = $this->service()->sync($user);

        $this->assertSame('First Activity', $plan->next_action_json['label']);
        $this->assertStringContainsString('/academy/module/'.$first->id, $plan->next_action_json['route']);
    }

    public function test_next_academy_activity_biases_frontier_toward_weakest_skill(): void
    {
        // Endgame is the clear weakest needed skill.
        $user = $this->makeUser(['placement_elo' => 1200]);
        $this->seedAssessment($user, [
            'tactics' => ['accuracy' => 0.85, 'count' => 6],
            'endgame' => ['accuracy' => 0.10, 'count' => 4],
        ]);

        $track = Track::create([
            'slug' => 'improver', 'name' => 'Improver', 'elo_min' => 1000, 'elo_max' => 1499,
            'display_order' => 1, 'published' => true,
        ]);
        $course = Course::create([
            'track_id' => $track->id, 'slug' => 'basics', 'name' => 'Basics', 'display_order' => 0,
        ]);

        // Earlier module trains strategy (a lesson) — not the weak skill.
        $early = Module::create([
            'course_id' => $course->id, 'slug' => 'm1', 'name' => 'Strategy Intro', 'display_order' => 0, 'published' => true,
        ]);
        Activity::create([
            'module_id' => $early->id, 'type' => 'lesson_markdown', 'title' => 'What is a plan?',
            'config' => [], 'display_order' => 0, 'published' => true,
        ]);
        // Later module trains endgame — the weak skill.
        $later = Module::create([
            'course_id' => $course->id, 'slug' => 'm2', 'name' => 'Endgame Basics', 'display_order' => 1, 'published' => true,
        ]);
        Activity::create([
            'module_id' => $later->id, 'type' => 'endgame_set', 'title' => 'King & Pawn',
            'config' => [], 'display_order' => 0, 'published' => true,
        ]);

        $plan = $this->service()->sync($user);

        // Skill bias jumps the frontier to the endgame activity, past the lesson.
        $this->assertSame('academy_activity', $plan->next_action_json['kind']);
        $this->assertSame('King & Pawn', $plan->next_action_json['label']);
        $this->assertStringContainsString('/academy/module/'.$later->id, $plan->next_action_json['route']);
        $this->assertStringContainsString('biggest gap', $plan->next_action_json['rationale']);
    }

    // -----------------------------------------------------------------
    // Peer-percentile benchmarking
    // -----------------------------------------------------------------

    private function makePeerPlan(int $elo, array $skillLevels, int $readiness): void
    {
        $peer = User::factory()->create(['placement_elo' => $elo, 'placement_completed_at' => now()]);
        $mastery = [];
        foreach ($skillLevels as $key => $lvl) {
            $mastery[$key] = ['level' => $lvl, 'score' => null, 'tier' => 'attempted', 'trend' => 'flat'];
        }
        ImprovementPlan::create([
            'user_id' => $peer->id,
            'placement_elo_at_creation' => $elo,
            'milestones_json' => [],
            'skill_mastery_json' => $mastery,
            'next_action_json' => [],
            'adaptation_log_json' => [],
            'readiness_score' => $readiness,
            'current_milestone_slug' => 'improver',
            'generated_at' => now(),
            'last_synced_at' => now(),
        ]);
    }

    public function test_peer_benchmark_is_unavailable_without_enough_peers(): void
    {
        $user = $this->makeUser(['placement_elo' => 1200]);
        $this->seedAssessment($user, ['tactics' => ['accuracy' => 0.5, 'count' => 4]]);
        $plan = $this->service()->sync($user);

        // Only 2 peers — below MIN_PEER_COHORT (4).
        $this->makePeerPlan(1200, ['tactics' => 1], 40);
        $this->makePeerPlan(1180, ['tactics' => 2], 45);

        $bench = $this->service()->peerBenchmark($user->fresh(), $plan->fresh());

        $this->assertFalse($bench['available']);
        $this->assertSame(2, $bench['cohort_size']);
        $this->assertNull($bench['readiness_percentile']);
    }

    public function test_peer_benchmark_ranks_user_against_same_band_cohort(): void
    {
        $user = $this->makeUser(['placement_elo' => 1200]);
        $this->seedAssessment($user, ['tactics' => ['accuracy' => 0.5, 'count' => 4]]);
        $plan = $this->service()->sync($user);
        // Pin a known mastery + readiness on the user's plan.
        $plan->update([
            'skill_mastery_json' => ['tactics' => ['level' => 3, 'score' => 1.5, 'tier' => 'proficient', 'trend' => 'flat']],
            'readiness_score' => 60,
        ]);

        // 4 in-band peers, all weaker than the user.
        foreach ([0, 1, 1, 2] as $lvl) {
            $this->makePeerPlan(1200, ['tactics' => $lvl], 40);
        }
        // An out-of-band peer that must NOT be counted (Elo far outside ±150).
        $this->makePeerPlan(2000, ['tactics' => 4], 95);

        $bench = $this->service()->peerBenchmark($user->fresh(), $plan->fresh());

        $this->assertTrue($bench['available']);
        $this->assertSame(4, $bench['cohort_size']);
        // User (level 3) is at or above all 4 peers → top of the cohort.
        $tactics = collect($bench['skills'])->firstWhere('key', 'tactics');
        $this->assertSame(100, $tactics['percentile']);
        // Readiness 60 vs four peers at 40 → 100th percentile.
        $this->assertSame(100, $bench['readiness_percentile']);
    }

    // -----------------------------------------------------------------
    // Trainer persona personalization (Cat 6)
    // -----------------------------------------------------------------

    public function test_trainer_persona_personalizes_copy_per_selected_trainer(): void
    {
        $queenUser = $this->makeUser(['selected_trainer_id' => 'queen']);
        $this->seedAssessment($queenUser, ['tactics' => ['accuracy' => 0.5, 'count' => 4]]);
        $queenPlan = $this->service()->sync($queenUser);

        $kingUser = $this->makeUser(['selected_trainer_id' => 'king']);
        $this->seedAssessment($kingUser, ['tactics' => ['accuracy' => 0.5, 'count' => 4]]);
        $kingPlan = $this->service()->sync($kingUser);

        // Copy reflects the selected trainer's name + tagline (config-seeded, never hard-coded).
        $this->assertStringContainsString((string) config('trainers.queen.name'), $queenPlan->trainer_intro);
        $this->assertStringContainsString((string) config('trainers.king.name'), $kingPlan->trainer_intro);
        $this->assertNotSame($queenPlan->trainer_intro, $kingPlan->trainer_intro);

        // The next-action trainer_line is also persona-framed.
        $this->assertStringContainsString((string) config('trainers.queen.name'), $queenPlan->next_action_json['trainer_line']);

        $this->assertSame('queen', $queenPlan->trainer_id);
        $this->assertSame('king', $kingPlan->trainer_id);
    }

    // -----------------------------------------------------------------
    // Idempotency / signals-hash guard (§7)
    // -----------------------------------------------------------------

    public function test_sync_is_idempotent_when_signals_unchanged(): void
    {
        $user = $this->makeUser();
        $this->seedAssessment($user, ['tactics' => ['accuracy' => 0.6, 'count' => 4]]);

        $first = $this->service()->sync($user);
        $hash = $first->signals_hash;
        $mastery = $first->skill_mastery_json;

        $second = $this->service()->sync($user);

        // Same hash → *_json untouched, but last_synced_at advances.
        $this->assertSame($hash, $second->signals_hash);
        $this->assertSame($mastery, $second->skill_mastery_json);
        $this->assertSame($first->id, $second->id);
    }

    // -----------------------------------------------------------------
    // ADAPTIVITY — the four required mutation cases (§7, Cat 5)
    // -----------------------------------------------------------------

    public function test_adaptivity_a_solving_puzzles_raises_tactics_mastery(): void
    {
        $user = $this->makeUser(['placement_elo' => 1200]);
        $this->seedAssessment($user, ['tactics' => ['accuracy' => 0.4, 'count' => 4]]);

        // Low puzzle rating first.
        DB::table('user_puzzle_ratings')->insert([
            'user_id' => $user->id, 'rating' => 1000, 'rd' => 350, 'updated_at' => now(),
        ]);
        $before = $this->service()->sync($user);
        $tacticsBefore = $before->skill_mastery_json['tactics']['score'];

        // Solve lots of puzzles and raise the puzzle rating well above placement.
        $this->solvePuzzles($user, 30, solved: true);
        DB::table('user_puzzle_ratings')->where('user_id', $user->id)->update(['rating' => 1700]);

        $after = $this->service()->sync($user);
        $tacticsAfter = $after->skill_mastery_json['tactics']['score'];

        $this->assertGreaterThan($tacticsBefore, $tacticsAfter);
        $this->assertSame('up', $after->skill_mastery_json['tactics']['trend']);
        $this->assertNotSame($before->signals_hash, $after->signals_hash);

        // A skill_up adaptation event was recorded.
        $types = array_column($after->adaptation_log_json, 'type');
        $this->assertContains('skill_up', $types);
    }

    public function test_adaptivity_b_changing_recent_game_accuracy_changes_plan(): void
    {
        $user = $this->makeUser(['placement_elo' => 1300]);
        $this->seedAssessment($user, ['calculation' => ['accuracy' => 0.5, 'count' => 3]]);

        // Low-accuracy analyzed game.
        $game = Game::create([
            'user_id' => $user->id, 'chess_com_game_id' => 'g-low', 'pgn' => '1. e4 e5',
            'white_username' => 'u', 'black_username' => 'o', 'user_color' => 'white',
            'result' => 'loss', 'white_accuracy' => 60.0, 'black_accuracy' => 80.0,
            'analyzed_at' => now(),
        ]);
        $before = $this->service()->sync($user);
        $calcBefore = $before->skill_mastery_json['calculation']['score'];

        // Raise accuracy dramatically.
        $game->update(['white_accuracy' => 95.0]);

        $after = $this->service()->sync($user);
        $calcAfter = $after->skill_mastery_json['calculation']['score'];

        $this->assertNotSame($before->signals_hash, $after->signals_hash);
        $this->assertGreaterThan($calcBefore, $calcAfter);
    }

    public function test_adaptivity_c_completing_academy_activity_bumps_milestone(): void
    {
        $user = $this->makeUser(['placement_elo' => 1200]);
        $this->seedAssessment($user, ['tactics' => ['accuracy' => 0.6, 'count' => 4]]);

        $before = $this->service()->sync($user);
        $actBefore = collect($before->milestones_json)
            ->firstWhere('slug', 'improver');
        $actCritBefore = collect($actBefore['criteria'])->firstWhere('key', 'activities')['current'];

        // Simulate completing 3 academy activities (real module row for the FK).
        $track = Track::create([
            'slug' => 'improver', 'name' => 'Improver', 'elo_min' => 1000, 'elo_max' => 1499,
            'display_order' => 1, 'published' => true,
        ]);
        $course = Course::create([
            'track_id' => $track->id, 'slug' => 'basics', 'name' => 'Basics', 'display_order' => 0,
        ]);
        $module = Module::create([
            'course_id' => $course->id, 'slug' => 'm1', 'name' => 'Module 1', 'display_order' => 0, 'published' => true,
        ]);
        DB::table('module_progress')->insert([
            'user_id' => $user->id, 'module_id' => $module->id, 'started_at' => now(),
            'activities_completed' => 3, 'activities_total' => 8,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $after = $this->service()->sync($user);
        $actAfter = collect($after->milestones_json)->firstWhere('slug', 'improver');
        $actCritAfter = collect($actAfter['criteria'])->firstWhere('key', 'activities')['current'];

        $this->assertSame(0, $actCritBefore);
        $this->assertSame(3, $actCritAfter);
        $this->assertGreaterThan($actCritBefore, $actCritAfter);
        $this->assertNotSame($before->signals_hash, $after->signals_hash);
    }

    public function test_adaptivity_d_crossing_rating_band_re_evaluates_milestone_and_logs_band_up(): void
    {
        $user = $this->makeUser(['placement_elo' => 1450, 'placement_track' => 'improver']);
        $this->seedAssessment($user, ['tactics' => ['accuracy' => 0.6, 'count' => 4]]);

        $before = $this->service()->sync($user);
        $this->assertSame('improver', $before->current_milestone_slug);

        // Cross into the Club band (placement_elo jump 1450 → 1650).
        $user->update(['placement_elo' => 1650, 'placement_track' => 'club']);

        $after = $this->service()->sync($user);

        $this->assertSame('club', $after->current_milestone_slug);

        // Milestone statuses re-evaluated: improver now done, club current.
        $byslug = collect($after->milestones_json)->keyBy('slug');
        $this->assertSame('done', $byslug['improver']['status']);
        $this->assertSame('current', $byslug['club']['status']);

        // band_up adaptation event appended.
        $types = array_column($after->adaptation_log_json, 'type');
        $this->assertContains('band_up', $types);

        // creation snapshot refreshed to the new placement.
        $this->assertSame(1650, $after->placement_elo_at_creation);
    }

    // -----------------------------------------------------------------
    // Endpoints (§9)
    // -----------------------------------------------------------------

    public function test_sync_endpoint_reports_changed_and_changes(): void
    {
        $user = $this->makeUser(['placement_elo' => 1450]);
        $this->seedAssessment($user, ['tactics' => ['accuracy' => 0.6, 'count' => 4]]);
        Sanctum::actingAs($user);

        // First sync creates the plan.
        $this->postJson('/api/improvement-plan/sync')->assertOk()->assertJsonPath('changed', true);

        // Cross a band → changed:true with a band_up change event.
        $user->update(['placement_elo' => 1650]);
        $res = $this->postJson('/api/improvement-plan/sync')->assertOk();
        $res->assertJsonPath('changed', true);
        $changes = $res->json('changes');
        $this->assertNotEmpty($changes);
        $this->assertContains('band_up', array_column($changes, 'type'));
    }

    public function test_complete_action_endpoint_returns_next_action(): void
    {
        $user = $this->makeUser();
        $this->seedAssessment($user, ['tactics' => ['accuracy' => 0.6, 'count' => 4]]);
        Sanctum::actingAs($user);

        $this->postJson('/api/improvement-plan/action/complete')
            ->assertOk()
            ->assertJsonStructure(['plan', 'next_action' => ['kind', 'label', 'route', 'skill']]);
    }

    // -----------------------------------------------------------------
    // Hook wiring (§7) — puzzle attempt + academy completion sync the plan.
    // -----------------------------------------------------------------

    public function test_puzzle_attempt_hook_creates_or_updates_plan(): void
    {
        $user = $this->makeUser();
        $this->seedAssessment($user, ['tactics' => ['accuracy' => 0.6, 'count' => 4]]);
        Sanctum::actingAs($user);

        DB::table('puzzles')->insert([
            'id' => 'hook-puzzle-1', 'fen' => '8/8/8/8/8/8/8/K6k w - - 0 1',
            'moves' => 'a1a2', 'themes' => 'fork', 'rating' => 1200, 'rating_deviation' => 100,
        ]);

        $this->postJson('/api/puzzles/hook-puzzle-1/attempt', ['solved' => true])->assertOk();

        $this->assertDatabaseHas('improvement_plans', ['user_id' => $user->id]);
    }

    // -----------------------------------------------------------------
    // Regression — "skip the quiz" (estimate-from-games) path must persist
    // placement even with no games, so the plan is not a dead end.
    // -----------------------------------------------------------------

    public function test_estimate_from_games_with_no_games_completes_placement_and_unlocks_plan(): void
    {
        $user = User::factory()->create([
            'selected_trainer_id' => 'king',
            'placement_elo' => null,
            'placement_track' => null,
            'placement_completed_at' => null,
        ]);

        $result = app(\App\Services\AdaptiveAssessmentService::class)->estimateFromGames($user);

        $this->assertSame('fallback_no_games', $result['source']);

        // Placement is now persisted (the bug was that it was not).
        $user->refresh();
        $this->assertNotNull($user->placement_elo);
        $this->assertNotNull($user->placement_completed_at);
        $this->assertSame('improver', $user->placement_track);

        // And the plan endpoint now returns a real plan, not placement_completed:false.
        Sanctum::actingAs($user);
        $this->getJson('/api/improvement-plan')
            ->assertOk()
            ->assertJsonPath('plan.placement_completed', true);
    }
}
