<?php

namespace App\Console\Commands;

use App\Models\MoveRationale;
use App\Models\OpeningLine;
use App\Services\GeminiCoachService;
use App\Services\PiperTtsService;
use Illuminate\Console\Command;

/**
 * Warms the Piper on-disk WAV cache for every authored move rationale so
 * first playback in the Learn page is instant instead of showing the 300-500 ms
 * cold-generate lag. Also warms the canned phrases the frontend plays on
 * correct quiz moves.
 *
 * Missing rationales are generated via Gemini first (same path the trainer
 * controller uses at runtime) so the command is safe to run on a fresh DB.
 */
class PregeneratePiperVoice extends Command
{
    protected $signature = 'piper:pregenerate
        {--voice= : Voice model name (defaults to the service default)}
        {--lines-only : Only synthesize existing rationales; skip Gemini generation}
        {--force : Re-synthesize even if the wav cache already has an entry}';

    protected $description = 'Pre-generate Piper TTS audio for all opening rationales and common phrases';

    /** Phrases the Learn page speaks verbatim — worth caching up-front. */
    private const CANNED_PHRASES = [
        // Correct-move fillers — see learn.tsx canned array
        'Nice.',
        'Exactly.',
        'Good.',
        'Right idea.',
        "That's it.",
        // Default quiz prompts
        "Your turn.",
        "Your move. White to play. What's the next book move here?",
        "Your move. Black to play. What's the next book move here?",
        "Your move. Your side to play. What's the next book move here?",
        // Trainer fallbacks
        "Hmm, let me think…",
        "I'll keep your progress locally for this session. Pick an opening to start.",
        "Welcome back — picking up where we left off. Select a line to continue.",
        "Welcome — I'm Alex, your chess coach. Pick an opening from the left and we'll learn it together.",
    ];

    public function handle(PiperTtsService $piper, GeminiCoachService $gemini): int
    {
        if (! $piper->isAvailable()) {
            $this->error('Piper binary or default voice model is missing. See chesiq/piper/README.md.');
            return self::FAILURE;
        }

        $voice = $this->option('voice') ?: null;
        $force = (bool) $this->option('force');

        // 1. Canned phrases
        $this->info('Warming canned phrases…');
        $phraseCount = 0;
        foreach (self::CANNED_PHRASES as $phrase) {
            if ($this->synthesize($piper, $phrase, $voice, $force)) {
                $phraseCount++;
            }
        }
        $this->line("  {$phraseCount}/" . count(self::CANNED_PHRASES) . ' phrases cached.');

        // 2. Walk every line in the catalog, fill in rationales, and voice them
        $lines = OpeningLine::orderBy('line_id')->get();
        if ($lines->isEmpty()) {
            $this->warn('No opening lines in the catalog. Run `php artisan db:seed --class=OpeningLinesSeeder` first.');
            return self::FAILURE;
        }

        $this->info("Processing {$lines->count()} lines…");
        $bar = $this->output->createProgressBar($lines->count());
        $bar->start();

        $totalRationales = 0;
        $generatedNow    = 0;
        $failures        = 0;

        foreach ($lines as $line) {
            $rationales = $this->ensureRationales($line, $gemini);
            $generatedNow += $rationales['generated'];

            foreach ($rationales['all'] as $r) {
                $totalRationales++;
                if (! $this->synthesize($piper, (string) $r->text, $voice, $force)) {
                    $failures++;
                }
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Rationales processed: {$totalRationales}");
        if ($generatedNow > 0) {
            $this->info("Rationales newly generated via Gemini: {$generatedNow}");
        }
        if ($failures > 0) {
            $this->warn("Piper synthesis failures: {$failures} (see laravel.log).");
        } else {
            $this->info('All rationales + canned phrases cached.');
        }

        return self::SUCCESS;
    }

    /**
     * Returns the set of MoveRationale rows for the line. If --lines-only is
     * not set, fills in any missing student-side indices via Gemini first.
     *
     * @return array{all: \Illuminate\Support\Collection, generated: int}
     */
    private function ensureRationales(OpeningLine $line, GeminiCoachService $gemini): array
    {
        $generated = 0;

        if (! $this->option('lines-only') && config('services.gemini.key')) {
            $moves = is_array($line->moves) ? $line->moves : [];
            $studentIndices = $this->studentMoveIndices($line->color, count($moves));
            $existingIndices = MoveRationale::where('line_id', $line->line_id)
                ->pluck('move_index')
                ->map(fn ($v) => (int) $v)
                ->all();
            $missing = array_values(array_diff($studentIndices, $existingIndices));

            if (! empty($missing)) {
                $newRationales = $gemini->generateLineRationales($line, $missing);
                foreach ($newRationales as $r) {
                    MoveRationale::updateOrCreate(
                        ['line_id' => $line->line_id, 'move_index' => $r['move_index']],
                        ['san' => $r['san'], 'text' => $r['text'], 'source' => 'generated']
                    );
                    $generated++;
                }
            }
        }

        $all = MoveRationale::where('line_id', $line->line_id)
            ->orderBy('move_index')
            ->get();

        return ['all' => $all, 'generated' => $generated];
    }

    /** Mirrors TrainerController::studentMoveIndices. */
    private function studentMoveIndices(string $color, int $lineLength): array
    {
        $out = [];
        for ($i = 0; $i < $lineLength; $i++) {
            $isStudent = match ($color) {
                'white' => $i % 2 === 0,
                'black' => $i % 2 === 1,
                default => true,
            };
            if ($isStudent) $out[] = $i;
        }
        return $out;
    }

    private function synthesize(PiperTtsService $piper, string $text, ?string $voice, bool $force): bool
    {
        $text = trim($text);
        if ($text === '') return false;

        if ($force) {
            // Simple --force path: wipe the specific cache file so synthesize
            // regenerates it. Relies on the service's internal cache layout.
            $cacheDir = storage_path('app/tts-cache');
            $voiceKey = $voice ?: 'en_US-amy-medium';
            $hash     = hash('sha256', $voiceKey . '|' . $text);
            $path     = "{$cacheDir}/{$voiceKey}/" . substr($hash, 0, 2) . "/{$hash}.wav";
            if (is_file($path)) @unlink($path);
        }

        return $piper->synthesize($text, $voice) !== null;
    }
}
