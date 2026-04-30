<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Wraps the Piper TTS binary (https://github.com/OHF-Voice/piper1-gpl) to turn
 * short coach lines into WAV audio. Results are cached to disk keyed on
 * sha256(voice|text), so identical lines across sessions cost zero CPU.
 *
 * Piper runs locally on CPU — no network calls, no API keys, no quotas.
 * Binary + voice models must be installed under base_path('piper/') — see
 * chesiq/piper/README.md for setup.
 */
class PiperTtsService
{
    /** Hard cap on input length. Longer text = longer CPU time = DoS surface. */
    private const MAX_TEXT_LENGTH = 2000;

    /** Per-request synthesis timeout (seconds). Piper is fast on CPU but guard against hangs. */
    private const PROCESS_TIMEOUT = 15;

    /** Default voice. Matches `piper/voices/{voice}.onnx` + `{voice}.onnx.json`. */
    private const DEFAULT_VOICE = 'en_US-amy-medium';

    /**
     * Synthesize text → WAV bytes. Returns null on any failure (binary missing,
     * voice missing, process error, timeout) so the caller can fall back to
     * browser TTS without surfacing a 500.
     */
    public function synthesize(string $text, ?string $voice = null): ?string
    {
        $text  = trim($text);
        $voice = $this->sanitizeVoice($voice ?? self::DEFAULT_VOICE);
        if ($text === '' || mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            return null;
        }

        $cachePath = $this->cachePathFor($voice, $text);
        if (is_file($cachePath)) {
            return (string) file_get_contents($cachePath);
        }

        $binary = $this->binaryPath();
        $model  = $this->modelPath($voice);
        if (! $binary || ! $model) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'piper-') . '.wav';

        try {
            $process = new Process([
                $binary,
                '--model', $model,
                '--output_file', $tmp,
            ]);
            // stdin carries the text — this avoids shell-escaping / argv length issues
            $process->setInput($text);
            $process->setTimeout(self::PROCESS_TIMEOUT);
            $process->run();
        } catch (ProcessTimedOutException $e) {
            Log::warning('Piper synthesis timeout', ['voice' => $voice, 'len' => mb_strlen($text)]);
            @unlink($tmp);
            return null;
        } catch (\Throwable $e) {
            Log::warning('Piper synthesis process error', ['error' => $e->getMessage()]);
            @unlink($tmp);
            return null;
        }

        if (! $process->isSuccessful() || ! is_file($tmp) || filesize($tmp) === 0) {
            Log::warning('Piper synthesis non-success', [
                'exit'    => $process->getExitCode(),
                'stderr'  => mb_substr($process->getErrorOutput(), 0, 500),
                'voice'   => $voice,
            ]);
            @unlink($tmp);
            return null;
        }

        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        // Write cache atomically so concurrent requests don't race
        @mkdir(dirname($cachePath), 0775, true);
        $cacheTmp = $cachePath . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($cacheTmp, $bytes) !== false) {
            @rename($cacheTmp, $cachePath);
        } else {
            @unlink($cacheTmp);
        }

        return $bytes;
    }

    /** True if the binary and default voice are both present — used for health checks. */
    public function isAvailable(): bool
    {
        return $this->binaryPath() !== null && $this->modelPath(self::DEFAULT_VOICE) !== null;
    }

    private function binaryPath(): ?string
    {
        $candidates = [
            base_path('piper/piper.exe'),
            base_path('piper/piper'),
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) return $path;
        }
        return null;
    }

    private function modelPath(string $voice): ?string
    {
        $path = base_path("piper/voices/{$voice}.onnx");
        return is_file($path) ? $path : null;
    }

    private function cachePathFor(string $voice, string $text): string
    {
        $hash = hash('sha256', $voice . '|' . $text);
        // Split into 2-char shards so a single dir doesn't accumulate 100k files
        return storage_path("app/tts-cache/{$voice}/" . substr($hash, 0, 2) . "/{$hash}.wav");
    }

    private function sanitizeVoice(string $voice): string
    {
        // Voice names are filesystem-facing — clamp to a safe alphabet
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', $voice) ?: self::DEFAULT_VOICE;
    }
}
