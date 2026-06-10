<?php

namespace Tests\Feature;

use App\Models\TeacherConversation;
use App\Models\TeacherFact;
use App\Models\TeacherMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the conversational AI Teacher backend: conversation start, the
 * student/teacher turn cycle, deterministic fallback when no Gemini key is
 * configured, trainer default_mode resolution, mode_override, position (FEN)
 * threading, and the every-12th-message summary trigger.
 *
 * These tests run with NO Gemini key, so every LLM path must degrade to its
 * deterministic fallback — the summary uses concatenation and creates no facts.
 */
class TeacherTest extends TestCase
{
    use RefreshDatabase;

    private function authedUser(array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        Sanctum::actingAs($user);
        return $user;
    }

    public function test_start_returns_a_conversation_id(): void
    {
        $this->authedUser();

        $resp = $this->postJson('/api/teacher/start')->assertOk();

        $id = $resp->json('conversation_id');
        $this->assertNotNull($id);
        $this->assertDatabaseHas('teacher_conversations', ['id' => $id]);
    }

    public function test_message_persists_student_and_teacher_messages_and_returns_teacher_reply(): void
    {
        $user = $this->authedUser();
        $conv = TeacherConversation::create(['user_id' => $user->id, 'started_at' => now()]);

        $resp = $this->postJson('/api/teacher/message', [
            'conversation_id' => $conv->id,
            'message' => 'Can you help me improve my game?',
        ])->assertOk();

        // Returns the teacher message.
        $resp->assertJsonPath('message.role', 'teacher');
        $this->assertNotEmpty($resp->json('message.content'));

        // Both a student row and a teacher row exist.
        $this->assertSame(1, TeacherMessage::where('conversation_id', $conv->id)->where('role', 'student')->count());
        $this->assertSame(1, TeacherMessage::where('conversation_id', $conv->id)->where('role', 'teacher')->count());
    }

    public function test_reply_uses_deterministic_fallback_with_no_key(): void
    {
        config(['services.gemini.key' => null]);

        $user = $this->authedUser();
        $conv = TeacherConversation::create(['user_id' => $user->id, 'started_at' => now()]);

        $resp = $this->postJson('/api/teacher/message', [
            'conversation_id' => $conv->id,
            'message' => 'I want to get better at chess.',
        ])->assertOk();

        // The deterministic fallback string is used (no network in tests).
        $this->assertStringContainsString('Tell me more', (string) $resp->json('message.content'));

        // A teacher message row is created.
        $teacher = TeacherMessage::where('conversation_id', $conv->id)->where('role', 'teacher')->first();
        $this->assertNotNull($teacher);
        $this->assertStringContainsString('Tell me more', $teacher->content);
    }

    public function test_default_mode_used_when_no_override_and_no_keyword_signal(): void
    {
        // Queen's default_mode is 'debate'. A neutral message has no keyword
        // signal, so the resolved mode should be the trainer default, not tutor.
        $user = $this->authedUser(['selected_trainer_id' => 'queen']);
        $conv = TeacherConversation::create(['user_id' => $user->id, 'started_at' => now()]);

        $this->postJson('/api/teacher/message', [
            'conversation_id' => $conv->id,
            'message' => 'Tell me about rook endgames.',
        ])->assertOk()->assertJsonPath('message.mode', 'debate');

        $this->assertDatabaseHas('teacher_messages', [
            'conversation_id' => $conv->id,
            'role' => 'student',
            'mode' => 'debate',
        ]);
    }

    public function test_mode_override_is_respected(): void
    {
        // Queen defaults to debate, but an explicit override wins.
        $user = $this->authedUser(['selected_trainer_id' => 'queen']);
        $conv = TeacherConversation::create(['user_id' => $user->id, 'started_at' => now()]);

        $this->postJson('/api/teacher/message', [
            'conversation_id' => $conv->id,
            'message' => 'Tell me about rook endgames.',
            'mode_override' => 'socratic',
        ])->assertOk()->assertJsonPath('message.mode', 'socratic');
    }

    public function test_keyword_signal_beats_trainer_default(): void
    {
        // Bishop defaults to tutor, but a "why" keyword is a strong socratic signal.
        $user = $this->authedUser(['selected_trainer_id' => 'bishop']);
        $conv = TeacherConversation::create(['user_id' => $user->id, 'started_at' => now()]);

        $this->postJson('/api/teacher/message', [
            'conversation_id' => $conv->id,
            'message' => 'Why did I lose that game?',
        ])->assertOk()->assertJsonPath('message.mode', 'socratic');
    }

    public function test_falls_back_to_tutor_with_no_trainer_and_neutral_message(): void
    {
        $user = $this->authedUser(['selected_trainer_id' => null]);
        $conv = TeacherConversation::create(['user_id' => $user->id, 'started_at' => now()]);

        $this->postJson('/api/teacher/message', [
            'conversation_id' => $conv->id,
            'message' => 'Tell me about pawn structure.',
        ])->assertOk()->assertJsonPath('message.mode', 'tutor');
    }

    public function test_message_with_fen_succeeds_and_persists(): void
    {
        $user = $this->authedUser();
        $conv = TeacherConversation::create(['user_id' => $user->id, 'started_at' => now()]);

        $resp = $this->postJson('/api/teacher/message', [
            'conversation_id' => $conv->id,
            'message' => 'What should I play here?',
            'fen' => 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1',
        ])->assertOk();

        $resp->assertJsonPath('message.role', 'teacher');
        $this->assertDatabaseHas('teacher_messages', [
            'conversation_id' => $conv->id,
            'role' => 'student',
        ]);
    }

    public function test_message_rejects_overlong_fen(): void
    {
        $user = $this->authedUser();
        $conv = TeacherConversation::create(['user_id' => $user->id, 'started_at' => now()]);

        $this->postJson('/api/teacher/message', [
            'conversation_id' => $conv->id,
            'message' => 'Look at this.',
            'fen' => str_repeat('x', 200),
        ])->assertStatus(422)->assertJsonValidationErrors(['fen']);
    }

    public function test_twelfth_message_triggers_summary_via_fallback_and_creates_no_facts(): void
    {
        config(['services.gemini.key' => null]);

        $user = $this->authedUser();
        $conv = TeacherConversation::create(['user_id' => $user->id, 'started_at' => now()]);

        // Each request creates a student + teacher message (2 messages). After 6
        // requests we have 12 messages → summarizeAndExtractFacts() fires.
        for ($i = 1; $i <= 6; $i++) {
            $this->postJson('/api/teacher/message', [
                'conversation_id' => $conv->id,
                'message' => "Question number {$i} about my endgame technique.",
            ])->assertOk();
        }

        $this->assertSame(12, TeacherMessage::where('conversation_id', $conv->id)->count());

        $conv->refresh();
        $this->assertNotEmpty($conv->summary_text);

        // No key → no facts created.
        $this->assertSame(0, TeacherFact::where('user_id', $user->id)->count());
    }

    public function test_memory_recall_ranks_summaries_by_relevance_over_recency(): void
    {
        $user = $this->authedUser();

        // Topically-relevant summary, but the OLDEST conversation — pure recency
        // ordering would bury it. Two newer summaries are about unrelated topics.
        TeacherConversation::create([
            'user_id' => $user->id,
            'started_at' => now()->subDays(10),
            'last_message_at' => now()->subDays(10),
            'summary_text' => 'We worked on rook endgame technique, the Lucena and Philidor positions.',
        ]);
        TeacherConversation::create([
            'user_id' => $user->id,
            'started_at' => now()->subDays(2),
            'last_message_at' => now()->subDays(2),
            'summary_text' => 'We discussed the Sicilian Najdorf opening and pawn structures.',
        ]);
        TeacherConversation::create([
            'user_id' => $user->id,
            'started_at' => now()->subDay(),
            'last_message_at' => now()->subDay(),
            'summary_text' => 'We covered knight outposts and piece activity in the middlegame.',
        ]);

        $ctx = app(\App\Services\TeacherService::class)
            ->teacherFullContext($user, 'How do I win a rook endgame?', null);

        $past = $ctx['memory']['past_summaries'];
        $this->assertIsArray($past);
        // Relevance beats recency: the rook-endgame summary surfaces first.
        $this->assertStringContainsString('rook endgame', $past[0]);
    }

    public function test_memory_recall_falls_back_to_recency_when_query_has_no_terms(): void
    {
        $user = $this->authedUser();

        $older = TeacherConversation::create([
            'user_id' => $user->id,
            'started_at' => now()->subDays(5),
            'last_message_at' => now()->subDays(5),
            'summary_text' => 'Older conversation about opening preparation.',
        ]);
        $newer = TeacherConversation::create([
            'user_id' => $user->id,
            'started_at' => now()->subDay(),
            'last_message_at' => now()->subDay(),
            'summary_text' => 'Newer conversation about defensive resources.',
        ]);

        // A stopword-only query yields no usable terms → legacy recency ordering.
        $ctx = app(\App\Services\TeacherService::class)
            ->teacherFullContext($user, 'what about it', null);

        $past = $ctx['memory']['past_summaries'];
        $this->assertSame($newer->summary_text, $past[0]);
        $this->assertContains($older->summary_text, $past);
    }

    public function test_transcribe_returns_501_when_no_backend_configured(): void
    {
        config(['services.gemini.key' => null]);
        $this->authedUser();

        $audio = UploadedFile::fake()->create('voice.webm', 10, 'audio/webm');

        $this->postJson('/api/teacher/transcribe', ['audio' => $audio])
            ->assertStatus(501);
    }

    public function test_transcribe_requires_an_audio_file(): void
    {
        config(['services.gemini.key' => 'test-key']);
        $this->authedUser();

        $this->postJson('/api/teacher/transcribe', [])
            ->assertStatus(422);
    }

    public function test_transcribe_returns_text_when_backend_configured(): void
    {
        config(['services.gemini.key' => 'test-key']);
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'How do I play the Sicilian Najdorf?']]],
                ]],
            ], 200),
        ]);
        $this->authedUser();

        // createWithContent → the temp file has real bytes, so the service reads
        // them and reaches the (faked) provider call.
        $audio = UploadedFile::fake()->createWithContent('voice.webm', 'fake-audio-bytes');

        $this->postJson('/api/teacher/transcribe', ['audio' => $audio])
            ->assertOk()
            ->assertJsonPath('text', 'How do I play the Sicilian Najdorf?');
    }

    public function test_transcribe_returns_422_when_backend_yields_nothing(): void
    {
        config(['services.gemini.key' => 'test-key']);
        // Provider reachable but returns no usable transcription (silence / garble).
        Http::fake([
            '*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => '']]]]],
            ], 200),
        ]);
        $this->authedUser();

        $audio = UploadedFile::fake()->createWithContent('voice.webm', 'fake-audio-bytes');

        $this->postJson('/api/teacher/transcribe', ['audio' => $audio])
            ->assertStatus(422);
    }
}
