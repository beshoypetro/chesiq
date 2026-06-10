<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/**
 * Server-side speech-to-text fallback for the Teacher mic.
 *
 * Chrome/Edge transcribe in-browser via the Web Speech API, but Safari, Firefox
 * and locked-down browsers don't — this is the graceful server fallback. It
 * reuses Gemini's multimodal audio understanding (the key is already configured
 * for the coaching surfaces), so there's no new provider or credential to set up.
 *
 * Degrades cleanly at every step: no key → null (the caller answers 501),
 * provider/network error → null, empty/garbled audio → null.
 */
class SpeechTranscriptionService
{
    /** Audio container mime types Gemini accepts for inline transcription. */
    public const SUPPORTED_MIME = [
        'audio/webm',
        'audio/ogg',
        'audio/wav',
        'audio/x-wav',
        'audio/mpeg',
        'audio/mp3',
        'audio/mp4',
        'audio/aac',
        'audio/flac',
        'audio/x-m4a',
    ];

    /** Max clip accepted, in kilobytes — a generous ceiling for a short voice note. */
    public const MAX_KILOBYTES = 25 * 1024;

    /** Whether a transcription backend is wired up at all. */
    public function isConfigured(): bool
    {
        return (bool) config('services.gemini.key');
    }

    /**
     * Map a detected upload mime to one Gemini accepts. Browser MediaRecorder
     * emits WebM/Ogg containers that PHP's finfo often reports as `video/*`;
     * Gemini transcribes the audio track fine when told it's `audio/*`. Anything
     * unrecognized defaults to `audio/webm` (the common Chrome output).
     */
    private function normalizeMime(?string $detected): string
    {
        $detected = strtolower((string) $detected);

        if (in_array($detected, self::SUPPORTED_MIME, true)) {
            return $detected;
        }

        return match ($detected) {
            'video/webm' => 'audio/webm',
            'video/ogg', 'application/ogg' => 'audio/ogg',
            'video/mp4' => 'audio/mp4',
            default => 'audio/webm',
        };
    }

    /**
     * Transcribe an uploaded audio clip to plain text. Returns null when not
     * configured, when the provider fails, or when there's no intelligible
     * speech — never throws.
     */
    public function transcribe(UploadedFile $audio): ?string
    {
        $apiKey = config('services.gemini.key');
        if (! $apiKey) {
            return null;
        }

        $bytes = @file_get_contents($audio->getRealPath());
        if ($bytes === false || $bytes === '') {
            return null;
        }

        $mime = $this->normalizeMime($audio->getMimeType());
        $model = config('services.gemini.model', 'gemini-2.0-flash');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        try {
            $response = Http::timeout(30)->post($url, [
                'contents' => [[
                    'role' => 'user',
                    'parts' => [
                        ['text' => 'Transcribe this audio of a chess student speaking to their coach. '
                            .'Return only the verbatim transcription as plain text — no commentary, no quotation marks, '
                            .'no speaker labels. If there is no intelligible speech, return nothing.'],
                        ['inline_data' => [
                            'mime_type' => $mime,
                            'data' => base64_encode($bytes),
                        ]],
                    ],
                ]],
                'generationConfig' => [
                    'maxOutputTokens' => 500,
                    // Deterministic: a transcription is a fact, not a creative reply.
                    'temperature' => 0.0,
                ],
            ]);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $text = trim((string) $response->json('candidates.0.content.parts.0.text', ''));

        return $text === '' ? null : $text;
    }
}
