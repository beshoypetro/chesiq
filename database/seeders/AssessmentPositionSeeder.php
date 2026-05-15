<?php

namespace Database\Seeders;

use App\Models\AssessmentPosition;
use Illuminate\Database\Seeder;

class AssessmentPositionSeeder extends Seeder
{
    public function run(): void
    {
        $positions = $this->bank();
        foreach ($positions as $p) {
            AssessmentPosition::updateOrCreate(
                ['fen' => $p['fen'], 'theme' => $p['theme']],
                $p,
            );
        }
    }

    /**
     * Curated seed bank — small but covers each theme × difficulty band so the adaptive
     * engine has something to serve at every Elo. Production should expand to ~100 positions
     * (chess-domain-expert agent task).
     */
    private function bank(): array
    {
        return [
            // ---- TACTICS ----
            [
                'theme' => 'tactics', 'difficulty_band' => 1, 'elo_target' => 700,
                'fen' => 'r1bqkbnr/pppp1ppp/2n5/4p3/2B1P3/5Q2/PPPP1PPP/RNB1K1NR w KQkq - 2 3',
                'question_kind' => 'best_move',
                'payload' => ['prompt' => 'Find the mating move (Scholar\'s Mate).', 'side' => 'w'],
                'correct_answer' => ['moves' => ['Qxf7#', 'Qxf7']],
                'explanation' => 'Queen captures f7 with mate — bishop covers, knight gone.',
            ],
            [
                'theme' => 'tactics', 'difficulty_band' => 2, 'elo_target' => 1100,
                'fen' => 'r2qk2r/pp1nbppp/2p1pn2/3pNb2/3P4/2N1P3/PPPB1PPP/R2QKB1R w KQkq - 0 7',
                'question_kind' => 'best_move',
                'payload' => ['prompt' => 'White to move. Find the strongest tactic.', 'side' => 'w'],
                'correct_answer' => ['moves' => ['Nxf7', 'Nxf7+']],
                'explanation' => 'Knight takes f7 forking queen and rook.',
            ],
            [
                'theme' => 'tactics', 'difficulty_band' => 3, 'elo_target' => 1500,
                'fen' => 'r1bq1rk1/ppp2ppp/2n2n2/2bpp3/4P3/2NP1N2/PPP1BPPP/R1BQ1RK1 w - - 0 7',
                'question_kind' => 'multiple_choice',
                'payload' => [
                    'prompt' => 'Best plan for White?',
                    'options' => [
                        'Trade pieces and play for the endgame',
                        'Push h3, g4 to attack the king',
                        'Re-route the knight via Nd2-Nf1-Ng3',
                        'Open the center with d4 immediately',
                    ],
                ],
                'correct_answer' => ['index' => 2],
                'explanation' => 'Spanish-style knight maneuver to support kingside play.',
            ],
            [
                'theme' => 'tactics', 'difficulty_band' => 4, 'elo_target' => 1900,
                'fen' => '2r3k1/5ppp/p3p3/1p1qP3/3P4/P1Q2N2/1P3PPP/4R1K1 w - - 0 1',
                'question_kind' => 'best_move',
                'payload' => ['prompt' => 'White to move. Convert the advantage.', 'side' => 'w'],
                'correct_answer' => ['moves' => ['Qc7', 'Qxc8+', 'Qxc8']],
                'explanation' => 'Invade c7 — the seventh-rank queen plus rook on e1 creates lethal pressure.',
            ],

            // ---- CALCULATION ----
            [
                'theme' => 'calculation', 'difficulty_band' => 1, 'elo_target' => 700,
                'fen' => '8/8/8/8/4k3/8/4P3/4K3 w - - 0 1',
                'question_kind' => 'multiple_choice',
                'payload' => [
                    'prompt' => 'Can White win this endgame?',
                    'options' => ['Yes — win', 'No — draw', 'Black wins'],
                ],
                'correct_answer' => ['index' => 1],
                'explanation' => 'King and pawn vs king — opposition is critical, this is drawn with correct play.',
            ],
            [
                'theme' => 'calculation', 'difficulty_band' => 2, 'elo_target' => 1200,
                'fen' => 'r3k2r/ppp2ppp/2nq1n2/3p4/3P4/2N2N2/PPP2PPP/R2QKB1R w KQkq - 0 9',
                'question_kind' => 'multiple_choice',
                'payload' => [
                    'prompt' => 'Move order matters: which is correct to develop?',
                    'options' => ['Bd3 then 0-0', 'Be2 then 0-0', '0-0-0 immediately', 'Bb5 pin'],
                ],
                'correct_answer' => ['index' => 0],
                'explanation' => 'Bd3 eyes h7; castle short follows naturally.',
            ],
            [
                'theme' => 'calculation', 'difficulty_band' => 3, 'elo_target' => 1600,
                'fen' => 'r4rk1/ppp2ppp/2n2q2/3pp3/3P4/2P1PN2/PP1N1PPP/R2Q1RK1 w - - 0 11',
                'question_kind' => 'best_move',
                'payload' => ['prompt' => 'White to move. Best continuation?', 'side' => 'w'],
                'correct_answer' => ['moves' => ['dxe5', 'Nxe5']],
                'explanation' => 'Capture in the center, opening lines while structurally sound.',
            ],
            [
                'theme' => 'calculation', 'difficulty_band' => 4, 'elo_target' => 2000,
                'fen' => '2r2rk1/1b3ppp/p3pn2/1p1q4/3PN3/P1Q1PN2/1P3PPP/2R2RK1 w - - 0 1',
                'question_kind' => 'best_move',
                'payload' => ['prompt' => 'White to move. Find the strongest plan.', 'side' => 'w'],
                'correct_answer' => ['moves' => ['Nfg5', 'Nxf6+']],
                'explanation' => 'Trade off the defender and lift the rook — typical IQP attack.',
            ],

            // ---- STRATEGY ----
            [
                'theme' => 'strategy', 'difficulty_band' => 1, 'elo_target' => 700,
                'fen' => 'rnbqkbnr/pppp1ppp/8/4p3/4P3/8/PPPP1PPP/RNBQKBNR w KQkq - 0 2',
                'question_kind' => 'multiple_choice',
                'payload' => [
                    'prompt' => 'What is the correct opening principle next?',
                    'options' => ['Develop a knight', 'Move the queen out', 'Push h-pawn', 'Move king'],
                ],
                'correct_answer' => ['index' => 0],
                'explanation' => 'Knights before bishops; queen stays home in the opening.',
            ],
            [
                'theme' => 'strategy', 'difficulty_band' => 2, 'elo_target' => 1200,
                'fen' => 'r1bqkbnr/pp3ppp/2n1p3/2pp4/2PP4/P1N2N2/1P2PPPP/R1BQKB1R w KQkq - 0 6',
                'question_kind' => 'multiple_choice',
                'payload' => [
                    'prompt' => 'White\'s main strategic asset?',
                    'options' => ['Bishop pair', 'Space advantage on queenside', 'Better king safety', 'Doubled pawns'],
                ],
                'correct_answer' => ['index' => 1],
                'explanation' => 'cxd5 and queenside expansion give White lasting space.',
            ],
            [
                'theme' => 'strategy', 'difficulty_band' => 3, 'elo_target' => 1700,
                'fen' => 'r2q1rk1/pp1bbppp/2n1pn2/2pp4/3P1B2/2PBPN2/PP1N1PPP/R2Q1RK1 w - - 0 9',
                'question_kind' => 'multiple_choice',
                'payload' => [
                    'prompt' => 'Best long-term plan for White?',
                    'options' => ['Minority attack with b4-b5', 'Kingside attack with Ng5', 'Trade everything', 'Push e4 immediately'],
                ],
                'correct_answer' => ['index' => 0],
                'explanation' => 'Carlsbad pawn structure → minority attack to create a weakness on c6.',
            ],

            // ---- ENDGAME ----
            [
                'theme' => 'endgame', 'difficulty_band' => 1, 'elo_target' => 700,
                'fen' => '8/8/8/3k4/8/3K4/3P4/8 w - - 0 1',
                'question_kind' => 'multiple_choice',
                'payload' => [
                    'prompt' => 'Key concept here?',
                    'options' => ['Take the opposition', 'Attack the pawn', 'Promote immediately', 'Sacrifice pawn'],
                ],
                'correct_answer' => ['index' => 0],
                'explanation' => 'King opposition decides K+P vs K endgames.',
            ],
            [
                'theme' => 'endgame', 'difficulty_band' => 2, 'elo_target' => 1200,
                'fen' => '8/4k3/8/4P3/4K3/8/8/8 w - - 0 1',
                'question_kind' => 'multiple_choice',
                'payload' => [
                    'prompt' => 'White to move and...',
                    'options' => ['Win', 'Draw'],
                ],
                'correct_answer' => ['index' => 1],
                'explanation' => 'King in front of own pawn but black king holds opposition — draw.',
            ],
            [
                'theme' => 'endgame', 'difficulty_band' => 3, 'elo_target' => 1700,
                'fen' => '8/p4k2/1p3p2/4p3/4P3/1P3P2/P4K2/8 w - - 0 1',
                'question_kind' => 'multiple_choice',
                'payload' => [
                    'prompt' => 'White\'s best plan?',
                    'options' => ['March king to queenside', 'Push f4 immediately', 'Trade pawns', 'Wait with king moves'],
                ],
                'correct_answer' => ['index' => 0],
                'explanation' => 'Active king + queenside majority — race the king to b4/a5.',
            ],
            [
                'theme' => 'endgame', 'difficulty_band' => 4, 'elo_target' => 2000,
                'fen' => '8/5pk1/6p1/3R3p/8/r5P1/5PKP/8 w - - 0 1',
                'question_kind' => 'multiple_choice',
                'payload' => [
                    'prompt' => 'Drawing technique for White?',
                    'options' => ['Active rook from behind', 'Pin the rook', 'Push h4', 'Trade rooks'],
                ],
                'correct_answer' => ['index' => 0],
                'explanation' => 'Lucena/Philidor — active rook is the universal R+P defense.',
            ],
        ];
    }
}
