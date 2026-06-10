<?php

/*
|--------------------------------------------------------------------------
| Coach line catalog — fixed, deterministic speech lines
|--------------------------------------------------------------------------
|
| Canonical source for every fixed line the coach speaks (session
| transitions, praise, corrections, wrap-ups). `php artisan tts:warm`
| pre-synthesizes all of these for every installed Piper voice so they are
| permanent disk-cache hits at runtime.
|
| MIRRORED in chessiq-review/src/lib/coachLines.ts — the strings must match
| byte-for-byte or the TTS cache misses. Edit both files together.
| Name-interpolated greetings live only on the frontend and are prewarmed
| per-user via POST /api/tts/prewarm at session start.
|
*/

return [
    'greetings' => [
        'Welcome back. Ready to work?',
        'Good to see you. Let\'s begin.',
    ],
    'transitions' => [
        'Let\'s look at your game from yesterday.',
        'Now, a quick exercise.',
        'One more, then we wrap up.',
        'Let\'s check your homework positions.',
    ],
    'praise' => [
        'Exactly right. Well done.',
        'That\'s the move. Good eyes.',
        'Perfect. You found it.',
        'Yes — that\'s precisely it.',
        'Strong choice. That\'s the best move.',
    ],
    'corrections' => [
        'Not quite. Take another look.',
        'That\'s not it — look again at the whole board.',
        'Close, but there\'s something better.',
        'Not this time. Watch for the tactic.',
        'That one slips. Try once more.',
    ],
    'reveals' => [
        'Here\'s the move you missed.',
        'Let me show you the idea.',
    ],
    'quiz_prompts' => [
        'Hold on — what would you play here? Show me on the board.',
    ],
    'walk_offers' => [
        'Fresh analysis is in. Want me to walk you through this one?',
    ],
    'wrapups' => [
        'Good session today. Same time tomorrow.',
        'That\'s a wrap. Keep this pattern in mind.',
        'Done for today — nice work.',
    ],
];
