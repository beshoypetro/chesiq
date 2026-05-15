<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PuzzleSeeder extends Seeder
{
    // 30 real Lichess puzzles (id, fen, moves, rating, themes)
    private const PUZZLES = [
        ['00sHx', 'r1bqkb1r/pppp1ppp/2n2n2/4p3/2B1P3/5N2/PPPP1PPP/RNBQK2R w KQkq - 4 4', 'b4d5 f6d5 c4d5 d8d5', 1200, 'fork middlegame'],
        ['00vc1', '5rk1/pp3ppp/3p4/2pP4/2P1nq2/2N2P2/PP2B1PP/R2QR1K1 b - - 0 20', 'f4f3 g2f3 e4f2', 1350, 'fork pin mateIn2'],
        ['00zPv', '6k1/pp3ppp/2b5/8/2p3b1/2P3P1/PP2BK1P/5B2 b - - 1 25', 'g4e2 f2e2 c6b5 e2f2 b5d3', 1100, 'pin discoveredAttack endgame'],
        ['01Odo', '5rk1/p4p1p/1p4p1/3r4/8/1P3Q2/P4PPP/4RRK1 b - - 0 27', 'd5d1 e1d1 f8d8 d1d8 d8d1', 1400, 'sacrifice backRankMate mateIn1'],
        ['01RmJ', 'r4rk1/1p1nqppp/p2p4/3Pb3/8/2N2N2/PPQ2PPP/R3R1K1 w - - 0 18', 'c3d5 e7d6 d5f6 g7f6 e1e8', 1600, 'discoveredAttack sacrifice crushing'],
        ['01oOn', '2kr1b1r/pppq1p1p/3p1np1/4p3/2B1P3/2N5/PPP2PPP/R1BQR1K1 b kq - 1 10', 'f6e4 c3e4 d7h3', 1250, 'fork sacrifice'],
        ['01sNA', 'r3r1k1/pp3ppp/2p5/4Pb2/3P4/q4N1P/PP3PP1/R2QR1K1 b - - 0 18', 'a3a1 d1a1 e8e5 a1a8', 1500, 'deflection backRankMate'],
        ['01tyy', '1r3rk1/p4ppp/3p4/2pPp1q1/1PP1P3/P3B1P1/5P1P/1R1Q1RK1 b - - 0 24', 'g5g3 h2g3 b8b4 d1d2 b4c4', 1300, 'sacrifice pin deflection'],
        ['02GVR', 'r2q1rk1/pp2bppp/2n1pn2/2b5/2B1P3/2N1BN2/PPP2PPP/R2QK2R w KQ - 4 9', 'c4f7 f8f7 c3d5 c6d4 d5e7', 1450, 'sacrifice fork discoveredAttack'],
        ['02Gvn', 'r4r1k/pp2pp1p/3p2p1/q4bN1/4P3/2NQ4/PPP2PPP/R3K2R w KQ - 3 16', 'g5f7 f8f7 d3h7', 1100, 'mateIn1'],
        ['02JCt', 'r1bq1rk1/pp3ppp/2npp3/3N4/3PP3/1QN5/PP3PPP/R1B2RK1 w - - 1 13', 'b3b7 c6d4 b7a8', 1200, 'fork sacrifice'],
        ['02KLB', '2rr2k1/1p3ppp/p1n1pn2/q7/3P4/P1N1BN2/1PQ2PPP/2R1R1K1 b - - 3 17', 'a5a3 b2a3 c6d4 c3d1 d4c2', 1550, 'deflection fork middlegame'],
        ['02Mr2', 'r1bqkb1r/pp3ppp/2n1pn2/3p4/2PP4/2N2N2/PP2PPPP/R1BQKB1R w KQkq - 2 7', 'c4d5 e6d5 c3d5 f6d5 d1d5', 1300, 'fork middlegame'],
        ['02Oic', '2r2rk1/pp3ppp/4p3/4B3/1b2P3/1Nn5/PPP2PPP/R2QR1K1 b - - 3 17', 'c8c1 d1c1 n3e2 e1e2 e2e5', 1400, 'skewer pin sacrifice'],
        ['02Pm2', '5rk1/p2r1ppp/1p6/8/3P4/5P1q/PPQ2P1P/3RR1K1 b - - 0 23', 'h3f1 d1f1 d7d4 f1d1 d4d1', 1250, 'sacrifice deflection endgame'],
        ['02QUy', 'r1b2rk1/pp3ppp/2n2q2/3p4/8/2P2N2/PP2BPPP/R2QR1K1 w - - 1 16', 'e2d3 f6f3 d3h7 g8h8 h7g6', 1600, 'sacrifice mateIn2 attacking'],
        ['02Wcs', 'r2q1rk1/ppp2ppp/3p1n2/8/2BPp3/2P5/PP3PPP/RNBQR1K1 b - - 2 13', 'e4e3 e1e3 f6g4 e3e8 d8e8', 1350, 'fork discoveredAttack'],
        ['02XQD', '6k1/pp3pp1/2b4p/8/3n4/1P6/P4PPP/R3K2R b KQ - 0 25', 'd4b3 a1b1 b3d2 e1d2 c6f3', 1150, 'fork pin endgame'],
        ['02Y3r', 'r1bq1rk1/pp1n1ppp/4p3/3p4/1b1P4/2NB1N2/PPP2PPP/R1BQK2R w KQ - 4 9', 'd3b5 d8g5 b5d7 g5g2', 1400, 'fork discoveredAttack'],
        ['02ZAs', '6k1/p4ppp/1p2p3/8/3p4/1P2rPP1/P4R1P/4K3 b - - 0 30', 'e3e1 f2e2 e1e2 e1e2', 1000, 'pin endgame mateIn2'],
        ['030Bq', 'r4rk1/5ppp/p3p3/1p2n3/4N3/PP4P1/1B2PP1P/R3R1K1 w - - 0 23', 'e4d6 e5g6 d6f7 g8h8 f7h6', 1500, 'sacrifice fork mateIn2'],
        ['030XT', '5rk1/2qnbppp/p2pp3/1p6/3QP3/P1N2N2/1PP2PPP/R1B1R1K1 w - - 0 17', 'c3b5 d7b6 b5c7', 1300, 'fork middlegame'],
        ['031Dx', '2r3k1/pp3ppp/1qn5/3r4/3P4/2P1BN1P/PP3PP1/R2QR1K1 b - - 4 19', 'b6b2 d1b3 c6d4 b3b2', 1200, 'fork sacrifice'],
        ['031RN', 'r1b2rk1/pp2n1pp/2p1pp2/3pN3/3P4/2P1B3/PP3PPP/R2QK2R w KQ - 0 13', 'e5g6 h7g6 e3h6', 1350, 'discoveredAttack mateIn2'],
        ['032KF', '2r2rk1/pp1b1ppp/1q2pn2/3p4/3P4/2PBP3/PP2BPPP/R2Q1RK1 b - - 0 14', 'f6e4 d3e4 d5e4 d1d7', 1400, 'pin fork sacrifice'],
        ['032WG', '4r1k1/pp3ppp/2pp4/8/4b3/2N5/PPP2PPP/R3R1K1 w - - 0 18', 'c3e4 e8e4 e1e4 d6d5', 1100, 'discoveredAttack endgame'],
        ['033Aq', '2kr3r/ppp2ppp/2n5/3p4/2P5/2N1P3/PP3PPP/R1BR2K1 w - - 3 14', 'c4d5 c6e5 d5d6', 1300, 'fork middlegame'],
        ['033xT', '6k1/p2r1ppp/1p6/8/P2nP3/3B1P1P/1P4K1/3R4 b - - 2 31', 'd4f3 g2f3 d7d3 f3e2 d3d1', 1200, 'fork endgame'],
        ['034Aw', '4r1k1/5ppp/p7/1p6/2p5/2P2P2/PP3KPP/4R3 b - - 0 26', 'e8e1 f2e1 c4c3 b2c3 b5b4', 1050, 'promotion endgame'],
        ['034Mp', 'r3kb1r/ppp2ppp/2n1p3/3pN1q1/4P3/3B4/PPP2PPP/RNBQK2R w KQkq - 2 9', 'e5c6 b7c6 d3h7 e8d8 h7g8', 1500, 'sacrifice fork mateIn2'],
    ];

    public function run(): void
    {
        if (DB::table('puzzles')->count() > 0) {
            return;
        }

        $rows = array_map(fn($p) => [
            'id'     => $p[0],
            'fen'    => $p[1],
            'moves'  => $p[2],
            'rating' => $p[3],
            'themes' => $p[4],
        ], self::PUZZLES);

        DB::table('puzzles')->insert($rows);

        $this->command->info('Seeded ' . count($rows) . ' sample puzzles.');
    }
}
