# Piper TTS — local voice for Alex

This folder holds the Piper binary and voice-model files that
`App\Services\PiperTtsService` shells out to. Piper is a free, offline,
CPU-only neural TTS engine (MIT-licensed), so there are no API keys,
quotas, or external services involved — but the binary and at least
one voice model must be present for `/api/tts` to return audio.

If these files are missing, `/api/tts` returns **503** and the frontend
falls back to the browser's built-in `speechSynthesis` automatically.
Nothing breaks; the voice just goes back to sounding robotic.

## Layout expected by the service

```
chesiq/piper/
├── piper.exe                         # Windows binary (or `piper` on Linux/macOS)
├── piper/...                          # bundled runtime files that ship with the zip
├── voices/
│   ├── en_US-amy-medium.onnx          # default voice
│   └── en_US-amy-medium.onnx.json
```

`PiperTtsService::binaryPath()` checks for both `piper.exe` and `piper`,
so the same repo works across operating systems.

## One-time setup (Windows)

The standalone Windows binary is at the original `rhasspy/piper` project.
The newer `OHF-Voice/piper1-gpl` repo only ships Python wheels — not what
we want for a shell-out CLI.

1. Download the Windows binary zip (~19 MB):
   https://github.com/rhasspy/piper/releases/download/2023.11.14-2/piper_windows_amd64.zip
2. Extract so that `chesiq/piper/piper.exe` exists. The zip contains a
   `piper/` folder — copy **its contents** into `chesiq/piper/` so you
   end up with `piper.exe`, `espeak-ng-data/`, and several `.dll` files
   sitting directly in this directory.
3. Download the default voice (both files required):
   - https://huggingface.co/rhasspy/piper-voices/resolve/main/en/en_US/amy/medium/en_US-amy-medium.onnx      (~60 MB)
   - https://huggingface.co/rhasspy/piper-voices/resolve/main/en/en_US/amy/medium/en_US-amy-medium.onnx.json (small config)

   Save both into `chesiq/piper/voices/`.
4. Verify from PowerShell:
   ```powershell
   cd chesiq/piper
   "hello world" | ./piper.exe --model voices/en_US-amy-medium.onnx --output_file test.wav
   ```
   A `test.wav` file containing the spoken phrase should appear. Delete
   it after you confirm playback.

After setup, Laravel will auto-detect the binary on the next request —
no restart required.

## One-time setup (Linux / macOS)

Grab the matching tarball from the same rhasspy/piper release
(`2023.11.14-2`):

- https://github.com/rhasspy/piper/releases/download/2023.11.14-2/piper_linux_x86_64.tar.gz
- https://github.com/rhasspy/piper/releases/download/2023.11.14-2/piper_macos_aarch64.tar.gz
- https://github.com/rhasspy/piper/releases/download/2023.11.14-2/piper_macos_x64.tar.gz

Extract so `chesiq/piper/piper` exists, then `chmod +x chesiq/piper/piper`.
Voice files install the same way as on Windows.

## Swapping voices

To try a different voice, drop its `.onnx` + `.onnx.json` into `voices/`
and send `{"voice": "en_GB-jenny_dioco-medium"}` in the `/api/tts`
request body. The default in `PiperTtsService` is `en_US-amy-medium`,
change `DEFAULT_VOICE` in the service if you want a different default
for every caller.

## Cache

Generated WAVs are cached to `chesiq/storage/app/tts-cache/<voice>/<shard>/<sha>.wav`
keyed on `sha256(voice|text)`. Safe to wipe at any time:
```bash
rm -rf chesiq/storage/app/tts-cache/*
```

## Why this folder is gitignored

The binary is ~20 MB and voice models are ~60 MB each — not something
you want in git history. Only this README is checked in (see
`chesiq/.gitignore`).
