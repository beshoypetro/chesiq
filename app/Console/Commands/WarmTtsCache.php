<?php

namespace App\Console\Commands;

use App\Services\PiperTtsService;
use Illuminate\Console\Command;

/**
 * Pre-synthesizes the fixed coach line catalog (config/coach_lines.php) for
 * every installed Piper voice, so greetings/transitions/praise lines are
 * permanent disk-cache hits at runtime. Idempotent — already-cached lines
 * cost nothing. Run once at deploy and after editing the catalog.
 */
class WarmTtsCache extends Command
{
    protected $signature = 'tts:warm {--voice= : Warm a single voice instead of all installed voices}';

    protected $description = 'Pre-synthesize the coach line catalog into the Piper TTS cache for all installed voices';

    public function handle(PiperTtsService $piper): int
    {
        if (! $piper->isAvailable()) {
            $this->error('Piper binary or default voice not found under piper/ — nothing to warm.');

            return self::FAILURE;
        }

        $voices = $this->option('voice')
            ? [$this->option('voice')]
            : $this->installedVoices();

        if ($voices === []) {
            $this->error('No voice models found under piper/voices/.');

            return self::FAILURE;
        }

        $lines = collect(config('coach_lines', []))->flatten()->filter()->values();
        if ($lines->isEmpty()) {
            $this->error('config/coach_lines.php is empty — nothing to warm.');

            return self::FAILURE;
        }

        $total = 0;
        $failed = 0;
        foreach ($voices as $voice) {
            $this->info("Warming {$voice} ({$lines->count()} lines)...");
            foreach ($lines as $line) {
                if ($piper->synthesize($line, $voice) !== null) {
                    $total++;
                } else {
                    $failed++;
                    $this->warn("  failed: {$line}");
                }
            }
        }

        $this->info("Done. {$total} lines cached".($failed ? ", {$failed} failed" : '').'.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<string> voice model names found under piper/voices/ */
    private function installedVoices(): array
    {
        $files = glob(base_path('piper/voices/*.onnx')) ?: [];

        return array_values(array_map(
            fn (string $path) => basename($path, '.onnx'),
            $files,
        ));
    }
}
