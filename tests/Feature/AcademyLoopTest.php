<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Module;
use App\Models\ModuleCertificate;
use App\Models\ModuleProgress;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Academy LOOP backend gate: activity content resolution (runner data),
 * idempotent activity completion → module completion → single certificate,
 * and placement-driven auto-enroll.
 */
class AcademyLoopTest extends TestCase
{
    use RefreshDatabase;

    /** Build a track → course → module and return the module. */
    private function seedModule(array $activities = [], string $trackSlug = 'improver'): Module
    {
        $track = Track::create([
            'slug' => $trackSlug, 'name' => ucfirst($trackSlug),
            'elo_min' => 1000, 'elo_max' => 1499,
            'display_order' => 1, 'published' => true,
        ]);
        $course = Course::create([
            'track_id' => $track->id, 'slug' => 'basics', 'name' => 'Basics', 'display_order' => 0,
        ]);
        $module = Module::create([
            'course_id' => $course->id, 'slug' => 'm1', 'name' => 'Endgame Essentials',
            'display_order' => 0, 'published' => true,
        ]);

        foreach ($activities as $i => $a) {
            Activity::create(array_merge([
                'module_id' => $module->id,
                'display_order' => $i,
                'published' => true,
            ], $a));
        }

        return $module;
    }

    private function seedPuzzle(string $id, string $themes, int $rating): void
    {
        DB::table('puzzles')->insert([
            'id' => $id,
            'fen' => '8/8/8/8/8/8/8/K6k w - - 0 1',
            'moves' => 'a1a2 h1h2',
            'themes' => $themes,
            'rating' => $rating,
            'rating_deviation' => 100,
        ]);
    }

    // -----------------------------------------------------------------
    // Activity content resolution (the runner data)
    // -----------------------------------------------------------------

    public function test_activity_endpoint_resolves_lesson_markdown(): void
    {
        $markdown = "# Opposition\nKey idea...";
        $module = $this->seedModule([[
            'type' => 'lesson_markdown',
            'title' => 'The Opposition',
            'config' => ['markdown' => $markdown],
        ]]);
        $activity = $module->activities()->first();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson("/api/academy/activities/{$activity->id}")
            ->assertOk()
            ->assertJsonPath('activity.type', 'lesson_markdown')
            ->assertJsonPath('activity.title', 'The Opposition')
            ->assertJsonPath('activity.module_id', $module->id)
            ->assertJsonPath('activity.module_name', 'Endgame Essentials')
            ->assertJsonPath('content.markdown', $markdown);
    }

    public function test_activity_endpoint_resolves_quiz_questions(): void
    {
        $questions = [[
            'kind' => 'multiple_choice',
            'prompt' => 'Best move?',
            'options' => ['a', 'b'],
            'correct_index' => 1,
            'explanation' => 'because',
        ]];
        $module = $this->seedModule([[
            'type' => 'quiz',
            'title' => 'Quiz 1',
            'config' => ['questions' => $questions],
        ]]);
        $activity = $module->activities()->first();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson("/api/academy/activities/{$activity->id}")
            ->assertOk()
            ->assertJsonPath('content.questions.0.prompt', 'Best move?')
            ->assertJsonPath('content.questions.0.correct_index', 1);
    }

    public function test_activity_endpoint_resolves_puzzle_set_to_puzzle_ids(): void
    {
        $this->seedPuzzle('pz-fork-1', 'fork middlegame', 1200);
        $this->seedPuzzle('pz-fork-2', 'fork', 1300);
        $this->seedPuzzle('pz-other', 'pin', 1200);

        $module = $this->seedModule([[
            'type' => 'puzzle_set',
            'title' => 'Forks',
            'config' => ['theme' => 'fork', 'elo_band' => 'improver', 'count' => 5],
        ]]);
        $activity = $module->activities()->first();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $res = $this->getJson("/api/academy/activities/{$activity->id}")
            ->assertOk()
            ->assertJsonPath('activity.type', 'puzzle_set');

        $ids = $res->json('content.puzzle_ids');
        $this->assertContains('pz-fork-1', $ids);
        $this->assertContains('pz-fork-2', $ids);
        $this->assertNotContains('pz-other', $ids);
        // Rows are returned too so the runner can launch without a second fetch.
        $this->assertNotEmpty($res->json('content.puzzles'));
    }

    public function test_activity_endpoint_resolves_puzzle_set_with_explicit_ids(): void
    {
        $this->seedPuzzle('explicit-1', 'pin', 1400);

        $module = $this->seedModule([[
            'type' => 'puzzle_set',
            'title' => 'Hand-picked',
            'config' => ['puzzle_ids' => ['explicit-1']],
        ]]);
        $activity = $module->activities()->first();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson("/api/academy/activities/{$activity->id}")
            ->assertOk()
            ->assertJsonPath('content.puzzle_ids', ['explicit-1']);
    }

    public function test_activity_endpoint_resolves_endgame_set(): void
    {
        $module = $this->seedModule([[
            'type' => 'endgame_set',
            'title' => 'Rook endings',
            'config' => ['category' => 'rook', 'count' => 8],
        ]]);
        $activity = $module->activities()->first();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson("/api/academy/activities/{$activity->id}")
            ->assertOk()
            ->assertJsonPath('content.category', 'rook')
            ->assertJsonPath('content.count', 8);
    }

    // -----------------------------------------------------------------
    // Completion → module completion → certificate
    // -----------------------------------------------------------------

    public function test_completing_all_activities_completes_module_and_issues_one_certificate(): void
    {
        $module = $this->seedModule([
            ['type' => 'lesson_markdown', 'title' => 'A', 'config' => ['markdown' => 'a']],
            ['type' => 'lesson_markdown', 'title' => 'B', 'config' => ['markdown' => 'b']],
        ]);
        $activities = $module->activities()->get();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // First activity → module not complete yet.
        $this->postJson("/api/academy/activities/{$activities[0]->id}/complete")
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('module_completed', false)
            ->assertJsonPath('certificate', null);

        // Second activity → module complete, certificate issued.
        $res = $this->postJson("/api/academy/activities/{$activities[1]->id}/complete")
            ->assertOk()
            ->assertJsonPath('module_completed', true);
        $this->assertNotNull($res->json('certificate.serial'));

        // module_progress is marked complete with full count.
        $progress = ModuleProgress::where('user_id', $user->id)->where('module_id', $module->id)->first();
        $this->assertNotNull($progress->completed_at);
        $this->assertSame(2, $progress->activities_completed);
        $this->assertSame(2, $progress->activities_total);

        // Exactly one certificate.
        $this->assertSame(1, ModuleCertificate::where('user_id', $user->id)->where('module_id', $module->id)->count());
    }

    public function test_re_completing_an_activity_is_idempotent_and_does_not_duplicate_certificate(): void
    {
        $module = $this->seedModule([
            ['type' => 'lesson_markdown', 'title' => 'A', 'config' => ['markdown' => 'a']],
        ]);
        $activity = $module->activities()->first();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // Complete the single activity twice.
        $this->postJson("/api/academy/activities/{$activity->id}/complete")->assertOk()
            ->assertJsonPath('module_completed', true);
        $this->postJson("/api/academy/activities/{$activity->id}/complete")->assertOk()
            ->assertJsonPath('module_completed', true);

        // Count stays at 1 (no double-count), one certificate only.
        $progress = ModuleProgress::where('user_id', $user->id)->where('module_id', $module->id)->first();
        $this->assertSame(1, $progress->activities_completed);
        $this->assertSame(1, ModuleCertificate::where('user_id', $user->id)->where('module_id', $module->id)->count());
    }

    public function test_complete_accepts_optional_score_and_passed_body(): void
    {
        $module = $this->seedModule([
            ['type' => 'quiz', 'title' => 'Q', 'config' => ['questions' => []]],
        ]);
        $activity = $module->activities()->first();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/academy/activities/{$activity->id}/complete", [
            'score' => 80,
            'passed' => true,
        ])->assertOk()->assertJsonPath('ok', true);
    }

    // -----------------------------------------------------------------
    // Auto-enroll from placement
    // -----------------------------------------------------------------

    public function test_placement_finalize_auto_enrolls_into_first_course_and_seeds_module_progress(): void
    {
        // A track named 'improver' with a first course + first module.
        $module = $this->seedModule([
            ['type' => 'lesson_markdown', 'title' => 'A', 'config' => ['markdown' => 'a']],
            ['type' => 'lesson_markdown', 'title' => 'B', 'config' => ['markdown' => 'b']],
        ], trackSlug: 'improver');
        $course = $module->course;

        // User with no games → estimate-from-games lands on the 'improver' track.
        $user = User::factory()->create([
            'placement_elo' => null,
            'placement_track' => null,
            'placement_completed_at' => null,
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/assessment/estimate-from-games')
            ->assertOk()
            ->assertJsonPath('track', 'improver');

        // Enrollment exists for the first course (no duplicates).
        $this->assertSame(
            1,
            CourseEnrollment::where('user_id', $user->id)->where('course_id', $course->id)->count(),
        );

        // module_progress seeded for the first module with the right total.
        $progress = ModuleProgress::where('user_id', $user->id)->where('module_id', $module->id)->first();
        $this->assertNotNull($progress);
        $this->assertNotNull($progress->started_at);
        $this->assertSame(2, $progress->activities_total);
        $this->assertSame(0, $progress->activities_completed);
        $this->assertNull($progress->completed_at);
    }

    public function test_auto_enroll_is_idempotent_across_repeated_placement(): void
    {
        $module = $this->seedModule([
            ['type' => 'lesson_markdown', 'title' => 'A', 'config' => ['markdown' => 'a']],
        ], trackSlug: 'improver');
        $course = $module->course;

        $user = User::factory()->create([
            'placement_elo' => null, 'placement_track' => null, 'placement_completed_at' => null,
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/assessment/estimate-from-games')->assertOk();
        $this->postJson('/api/assessment/estimate-from-games')->assertOk();

        $this->assertSame(1, CourseEnrollment::where('user_id', $user->id)->where('course_id', $course->id)->count());
        $this->assertSame(1, ModuleProgress::where('user_id', $user->id)->where('module_id', $module->id)->count());
    }
}
