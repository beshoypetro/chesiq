<?php

namespace Database\Seeders;

use App\Models\MoveRationale;
use Illuminate\Database\Seeder;

class ItalianGameRationalesSeeder extends Seeder
{
    public function run(): void
    {
        $rationales = [
            // Giuoco Piano: e4 e5 Nf3 Nc6 Bc4 Bc5 c3 Nf6 d3 d6 Nbd2
            ['italian-giuoco',  0,  'e4',    "We open with the king's pawn. This claims the center and frees our bishop and queen to join the game."],
            ['italian-giuoco',  2,  'Nf3',   "We develop the knight with a threat — it attacks the e5 pawn and forces black to defend before playing freely."],
            ['italian-giuoco',  4,  'Bc4',   "Our bishop eyes f7, the most vulnerable square in black's position, and prepares short castling."],
            ['italian-giuoco',  6,  'c3',    "Quiet but purposeful. We're preparing to play d4 and challenge the center with full support."],
            ['italian-giuoco',  8,  'd3',    "A flexible, solid approach. We hold the center without committing yet and keep all our options open."],
            ['italian-giuoco',  10, 'Nbd2',  "A classical maneuver — the knight will re-route through f1 to g3, supporting a kingside attack later."],

            // Evans Gambit: e4 e5 Nf3 Nc6 Bc4 Bc5 b4 Bxb4 c3 Ba5 d4 exd4 O-O
            ['italian-evans',   0,  'e4',    "King's pawn again. In the Evans we want a fast, open, attacking game — the center is where it starts."],
            ['italian-evans',   4,  'Bc4',   "Same Italian setup, but we're about to deviate sharply. The bishop eyes f7 and prepares fireworks."],
            ['italian-evans',   6,  'b4',    "This is the Evans Gambit! We sacrifice a pawn to gain a tempo and accelerate our c3-d4 break."],
            ['italian-evans',   8,  'c3',    "We kick the bishop to clear the c-file and make room for the crucial d4 push that follows."],
            ['italian-evans',   10, 'd4',    "The point of the gambit — we crash open the center while leading in development. Black has to survive the storm."],
            ['italian-evans',   12, 'O-O',   "Tuck the king away before committing pieces forward. An Evans attack with an uncastled king is suicide."],
        ];

        foreach ($rationales as [$lineId, $idx, $san, $text]) {
            MoveRationale::updateOrCreate(
                ['line_id' => $lineId, 'move_index' => $idx],
                ['san' => $san, 'text' => $text]
            );
        }
    }
}
