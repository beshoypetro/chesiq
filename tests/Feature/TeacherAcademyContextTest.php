<?php

namespace Tests\Feature;

use App\Models\TeacherConversation;
use App\Models\User;
use App\Services\TeacherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Verifies that the AI Teacher is aware of the user's academy progress.
 *
 * Tests run with NO Gemini key — every LLM path degrades to the deterministic
 * fallback so no network is required.
 *
 * Coverage:
 *  - /teacher/message succeeds for a user with module_progress rows (academy context included).
 *  - teacherFullContext() / renderAcademyNote() includes the module name in the prompt text.
 *  - The focal-module path (module_id + activity_id posted in the request) is included.
 *  - Users with no module_progress rows get no academy note (empty string).
 *  - module_id validation: unknown id → 422.
 *  - activity_id is accepted as a plain integer (no exists check on activities table).
 */
class TeacherAcademyContextTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function authedUser(array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        Sanctum::actingAs($user);
        return $user;
    }

    /**
     * Seed the minimum academy hierarchy needed for module_progress:
     * track → course → module, then insert a module_progress row for the user.
     *
     * Returns the module id.
     */
    private function seedModuleProgress(
        User $user,
        string $moduleName = 'Rook Endgames',
        string $trackName = 'Foundations',
        int $activitiesCompleted = 2,
        int $activitiesTotal = 5,
        bool $completed = false,
    ): int {
        $trackId = DB::table('tracks')->insertGetId([
            'slug' => 'foundations',
            'name' => $trackName,
            'description' => null,
            'elo_min' => 0,
            'elo_max' => 1200,
            'display_order' => 1,
            'published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $courseId = DB::table('courses')->insertGetId([
            'track_id' => $trackId,
            'slug' => 'endgames-101',
            'name' => 'Endgames 101',
            'description' => null,
            'display_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $moduleId = DB::table('modules')->insertGetId([
            'course_id' => $courseId,
            'slug' => 'rook-endgames',
            'name' => $moduleName,
            'overview' => 'How to convert rook endgames reliably.',
            'display_order' => 1,
            'published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('module_progress')->insert([
            'user_id' => $user->id,
            'module_id' => $moduleId,
            'started_at' => now()->subDays(3),
            'completed_at' => $completed ? now()->subDay() : null,
            'activities_completed' => $activitiesCompleted,
            'activities_total' => $activitiesTotal,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $moduleId;
    }

    // -----------------------------------------------------------------------
    // 1. Endpoint smoke test — user with academy progress
    // -----------------------------------------------------------------------

    public function test_message_endpoint_succeeds_for_user_with_module_progress(): void
    {
        config(['services.gemini.key' => null]);

        $user = $this->authedUser();
        $this->seedModuleProgress($user);

        $conv = TeacherConversation::create(['user_id' => $user->id, 'started_at' => now()]);

        $resp = $this->postJson('/api/teacher/message', [
            'conversation_id' => $conv->id,
            'message' => 'What should I focus on next?',
        ])->assertOk();

        $resp->assertJsonPath('message.role', 'teacher');
        $this->assertNotEmpty($resp->json('message.content'));
    }

    // -----------------------------------------------------------------------
    // 2. Context shape — academy note contains module name
    // -----------------------------------------------------------------------

    public function test_teacher_full_context_includes_in_progress_module_name(): void
    {
        $user = User::factory()->create();
        $moduleId = $this->seedModuleProgress($user, moduleName: 'King Safety Essentials');

        $svc = app(TeacherService::class);
        $ctx = $svc->teacherFullContext($user, 'Tell me about this module', null);

        $this->assertArrayHasKey('academy', $ctx);
        $this->assertNotNull($ctx['academy']['in_progress']);
        $this->assertSame('King Safety Essentials', $ctx['academy']['in_progress']['module_name']);
    }

    public function test_render_academy_note_contains_module_name_and_track(): void
    {
        $user = User::factory()->create();
        $this->seedModuleProgress(
            $user,
            moduleName: 'Pawn Structures',
            trackName: 'Improver',
            activitiesCompleted: 3,
            activitiesTotal: 7,
        );

        $svc = app(TeacherService::class);
        $ctx = $svc->teacherFullContext($user, 'Help me', null);
        $note = $svc->renderAcademyNote($ctx['academy']);

        $this->assertStringContainsString('Pawn Structures', $note);
        $this->assertStringContainsString('Improver', $note);
        $this->assertStringContainsString('3/7', $note);
    }

    // -----------------------------------------------------------------------
    // 3. No academy progress — no note rendered
    // -----------------------------------------------------------------------

    public function test_render_academy_note_is_empty_for_user_with_no_progress(): void
    {
        $user = User::factory()->create();

        $svc = app(TeacherService::class);
        $ctx = $svc->teacherFullContext($user, 'Hello', null);
        $note = $svc->renderAcademyNote($ctx['academy']);

        $this->assertSame('', $note);
    }

    // -----------------------------------------------------------------------
    // 4. Focal module — module_id passed in request
    // -----------------------------------------------------------------------

    public function test_message_with_module_id_includes_focal_module_in_context(): void
    {
        config(['services.gemini.key' => null]);

        $user = $this->authedUser();
        $moduleId = $this->seedModuleProgress($user, moduleName: 'Bishop Pair Advantage');

        $conv = TeacherConversation::create(['user_id' => $user->id, 'started_at' => now()]);

        // The endpoint should accept module_id without error.
        $resp = $this->postJson('/api/teacher/message', [
            'conversation_id' => $conv->id,
            'message' => 'Explain this module to me.',
            'module_id' => $moduleId,
        ])->assertOk();

        $resp->assertJsonPath('message.role', 'teacher');
    }

    public function test_teacher_full_context_focal_module_contains_name_and_overview(): void
    {
        $user = User::factory()->create();
        $moduleId = $this->seedModuleProgress($user, moduleName: 'Queen vs Pawn');

        $svc = app(TeacherService::class);
        $ctx = $svc->teacherFullContext($user, 'About this module', null, null, $moduleId, null);

        $this->assertNotNull($ctx['academy']['focal_module']);
        $this->assertSame('Queen vs Pawn', $ctx['academy']['focal_module']['module_name']);
        $this->assertNotEmpty($ctx['academy']['focal_module']['module_overview']);
    }

    public function test_render_academy_note_mentions_focal_module_first(): void
    {
        $user = User::factory()->create();
        $moduleId = $this->seedModuleProgress($user, moduleName: 'Knight Outposts');

        $svc = app(TeacherService::class);
        $ctx = $svc->teacherFullContext($user, 'Explain', null, null, $moduleId, null);
        $note = $svc->renderAcademyNote($ctx['academy']);

        // Focal module line appears in the note.
        $this->assertStringContainsString('Knight Outposts', $note);
        // "asking specifically about" is the focal-module signal phrase.
        $this->assertStringContainsString('asking specifically about', $note);
    }

    // -----------------------------------------------------------------------
    // 5. Activity title included when activity_id matches module
    // -----------------------------------------------------------------------

    public function test_focal_module_includes_activity_title_when_provided(): void
    {
        $user = User::factory()->create();
        $moduleId = $this->seedModuleProgress($user, moduleName: 'Endgame Tactics');

        // Insert a matching activity row.
        $activityId = DB::table('activities')->insertGetId([
            'module_id' => $moduleId,
            'type' => 'puzzle_set',
            'title' => 'Rook vs King Drills',
            'config' => json_encode([]),
            'display_order' => 1,
            'published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $svc = app(TeacherService::class);
        $ctx = $svc->teacherFullContext($user, 'Help', null, null, $moduleId, $activityId);
        $note = $svc->renderAcademyNote($ctx['academy']);

        $this->assertStringContainsString('Rook vs King Drills', $note);
    }

    // -----------------------------------------------------------------------
    // 6. Validation — invalid module_id → 422
    // -----------------------------------------------------------------------

    public function test_message_rejects_invalid_module_id(): void
    {
        $user = $this->authedUser();
        $conv = TeacherConversation::create(['user_id' => $user->id, 'started_at' => now()]);

        $this->postJson('/api/teacher/message', [
            'conversation_id' => $conv->id,
            'message' => 'Help me.',
            'module_id' => 999999,   // Does not exist.
        ])->assertStatus(422)->assertJsonValidationErrors(['module_id']);
    }

    // -----------------------------------------------------------------------
    // 7. activity_id accepted as plain integer (no exists rule)
    // -----------------------------------------------------------------------

    public function test_message_accepts_activity_id_without_module_id(): void
    {
        config(['services.gemini.key' => null]);

        $user = $this->authedUser();
        $conv = TeacherConversation::create(['user_id' => $user->id, 'started_at' => now()]);

        // activity_id without module_id is allowed (module_id is nullable independently).
        $this->postJson('/api/teacher/message', [
            'conversation_id' => $conv->id,
            'message' => 'Some question.',
            'activity_id' => 42,
        ])->assertOk();
    }

    // -----------------------------------------------------------------------
    // 8. Recently completed modules appear in note
    // -----------------------------------------------------------------------

    public function test_render_academy_note_includes_recently_completed_modules(): void
    {
        $user = User::factory()->create();

        // Seed one in-progress + one completed module under the same track/course.
        $trackId = DB::table('tracks')->insertGetId([
            'slug' => 'club',
            'name' => 'Club Player',
            'description' => null,
            'elo_min' => 1200,
            'elo_max' => 1600,
            'display_order' => 2,
            'published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $courseId = DB::table('courses')->insertGetId([
            'track_id' => $trackId,
            'slug' => 'tactics',
            'name' => 'Tactics Course',
            'description' => null,
            'display_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $completedModuleId = DB::table('modules')->insertGetId([
            'course_id' => $courseId,
            'slug' => 'forks',
            'name' => 'Forks and Skewers',
            'overview' => null,
            'display_order' => 1,
            'published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $inProgressModuleId = DB::table('modules')->insertGetId([
            'course_id' => $courseId,
            'slug' => 'pins',
            'name' => 'Pins and Discovered Attacks',
            'overview' => null,
            'display_order' => 2,
            'published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('module_progress')->insert([
            ['user_id' => $user->id, 'module_id' => $completedModuleId, 'started_at' => now()->subWeek(), 'completed_at' => now()->subDays(2), 'activities_completed' => 4, 'activities_total' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $user->id, 'module_id' => $inProgressModuleId, 'started_at' => now()->subDay(), 'completed_at' => null, 'activities_completed' => 1, 'activities_total' => 4, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $svc = app(TeacherService::class);
        $ctx = $svc->teacherFullContext($user, 'What now?', null);
        $note = $svc->renderAcademyNote($ctx['academy']);

        $this->assertStringContainsString('Pins and Discovered Attacks', $note);
        $this->assertStringContainsString('Forks and Skewers', $note);
    }
}
