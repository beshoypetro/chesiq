<?php

namespace Database\Seeders;

use App\Models\OpeningLine;
use Illuminate\Database\Seeder;

/**
 * Seeds the authoritative server-side catalog of opening lines.
 *
 * Mirrors the OPENINGS array in chessiq-review/src/routes/learn.tsx.
 * When adding/updating lines here, mirror the change there too
 * (or refactor both to read from a shared JSON — tracked in CLAUDE.md).
 */
class OpeningLinesSeeder extends Seeder
{
    public function run(): void
    {
        $lines = [
            // ── 1.e4 e5 ────────────────────────────────────────────────────
            ['italian-giuoco',  'Italian Game',  'Giuoco Piano',      'C50', 'white', ['e4','e5','Nf3','Nc6','Bc4','Bc5','c3','Nf6','d3','d6','Nbd2']],
            ['italian-evans',   'Italian Game',  'Evans Gambit',      'C50', 'white', ['e4','e5','Nf3','Nc6','Bc4','Bc5','b4','Bxb4','c3','Ba5','d4','exd4','O-O']],
            ['italian-2k',      'Italian Game',  'Two Knights',       'C50', 'white', ['e4','e5','Nf3','Nc6','Bc4','Nf6','Ng5','d5','exd5','Na5','Bb5+','c6']],
            ['italian-fried',   'Italian Game',  'Fried Liver Attack','C50', 'white', ['e4','e5','Nf3','Nc6','Bc4','Nf6','Ng5','d5','exd5','Nxd5','Nxf7','Kxf7','Qf3+','Ke6']],

            ['ruy-morphy',      'Ruy Lopez',     'Morphy Defense',    'C60', 'white', ['e4','e5','Nf3','Nc6','Bb5','a6','Ba4','Nf6','O-O','Be7','Re1','b5','Bb3','d6','c3','O-O']],
            ['ruy-berlin',      'Ruy Lopez',     'Berlin Defense',    'C60', 'white', ['e4','e5','Nf3','Nc6','Bb5','Nf6','O-O','Nxe4','d4','Nd6','Bxc6','dxc6','dxe5','Nf5']],
            ['ruy-exchange',    'Ruy Lopez',     'Exchange',          'C60', 'white', ['e4','e5','Nf3','Nc6','Bb5','a6','Bxc6','dxc6','O-O','f6','d4','exd4','Nxd4']],
            ['ruy-antibert',    'Ruy Lopez',     'Anti-Berlin (d3)',  'C60', 'white', ['e4','e5','Nf3','Nc6','Bb5','Nf6','d3','Bc5','Bxc6','dxc6','Nbd2','O-O','O-O']],

            ['scotch-classical','Scotch Game',   'Classical',         'C45', 'white', ['e4','e5','Nf3','Nc6','d4','exd4','Nxd4','Bc5','Be3','Qf6','c3','Nge7','Bc4']],
            ['scotch-mieses',   'Scotch Game',   'Mieses-Kotroc',     'C45', 'white', ['e4','e5','Nf3','Nc6','d4','exd4','Nxd4','Nf6','Nxc6','bxc6','e5','Qe7','Qe2','Nd5','c4','Ba6']],
            ['scotch-gambit',   'Scotch Game',   'Scotch Gambit',     'C45', 'white', ['e4','e5','Nf3','Nc6','d4','exd4','Bc4','Nf6','e5','d5','Bb5','Ne4','Nxd4','Bd7']],

            ['vienna-gambit',   'Vienna Game',   'Vienna Gambit',     'C25', 'white', ['e4','e5','Nc3','Nc6','f4','exf4','Nf3','g5','h4','g4','Ng5','h6','Nxf7']],
            ['vienna-bishop',   'Vienna Game',   "Bishop's Opening",  'C25', 'white', ['e4','e5','Nc3','Nf6','Bc4','Bc5','d3','d6','f4','Nc6','Nf3','O-O']],

            ['kg-kieseritzky',  "King's Gambit", 'Kieseritzky',       'C30', 'white', ['e4','e5','f4','exf4','Nf3','g5','h4','g4','Ne5','Nf6','Bc4','d5','exd5','Bd6']],
            ['kg-declined',     "King's Gambit", 'Declined (Falkbeer)','C30','white', ['e4','e5','f4','d5','exd5','e4','d3','Nf6','dxe4','Nxe4','Nf3','Bc5','Qe2','Bf5','Nc3']],
            ['kg-bishop',       "King's Gambit", "Bishop's Gambit",   'C30', 'white', ['e4','e5','f4','exf4','Bc4','Nf6','Nc3','c6','Bb3','d5','exd5','cxd5','d4']],

            ['petrov-classical',"Petrov's Defense",'Classical',       'C42', 'black', ['e4','e5','Nf3','Nf6','Nxe5','d6','Nf3','Nxe4','d4','d5','Bd3','Nc6','O-O','Be7']],
            ['petrov-steinitz', "Petrov's Defense",'Steinitz Attack', 'C42', 'black', ['e4','e5','Nf3','Nf6','d4','exd4','e5','Ne4','Qxd4','d5','exd6','Nxd6','Bg5','Nc6']],

            // ── Sicilian ───────────────────────────────────────────────────
            ['sic-najdorf',     'Sicilian Defense','Najdorf',         'B20', 'black', ['e4','c5','Nf3','d6','d4','cxd4','Nxd4','Nf6','Nc3','a6','Be3','e5','Nb3','Be6']],
            ['sic-dragon',      'Sicilian Defense','Dragon',          'B20', 'black', ['e4','c5','Nf3','d6','d4','cxd4','Nxd4','Nf6','Nc3','g6','Be3','Bg7','f3','O-O','Qd2','Nc6']],
            ['sic-schev',       'Sicilian Defense','Scheveningen',    'B20', 'black', ['e4','c5','Nf3','e6','d4','cxd4','Nxd4','Nf6','Nc3','d6','Be2','Be7','O-O','O-O','f4']],
            ['sic-svesh',       'Sicilian Defense','Sveshnikov',      'B20', 'black', ['e4','c5','Nf3','Nc6','d4','cxd4','Nxd4','Nf6','Nc3','e5','Nb5','d6','Bg5','a6','Na3','b5']],
            ['sic-kan',         'Sicilian Defense','Kan (Taimanov)',  'B20', 'black', ['e4','c5','Nf3','e6','d4','cxd4','Nxd4','a6','Nc3','Qc7','Bd3','Nc6','Nxc6','bxc6','O-O','d6']],
            ['sic-alapin',      'Sicilian Defense','Alapin (c3)',     'B20', 'black', ['e4','c5','c3','d5','exd5','Qxd5','d4','Nf6','Nf3','e6','Na3','Nc6','Nb5','Qd8']],
            ['sic-grandprix',   'Sicilian Defense','Grand Prix Attack','B20','black', ['e4','c5','Nc3','Nc6','f4','g6','Nf3','Bg7','Bb5','Nd4','O-O','a6','Bxd4','cxd4']],

            // ── French & Caro ──────────────────────────────────────────────
            ['french-advance',  'French Defense','Advance',           'C00', 'black', ['e4','e6','d4','d5','e5','c5','c3','Nc6','Nf3','Qb6','a3','c4','Nbd2']],
            ['french-winawer',  'French Defense','Winawer',           'C00', 'black', ['e4','e6','d4','d5','Nc3','Bb4','e5','c5','a3','Bxc3+','bxc3','Ne7','Qg4','Qc7','Qxg7','Rg8']],
            ['french-tarrasch', 'French Defense','Tarrasch',          'C00', 'black', ['e4','e6','d4','d5','Nd2','Nf6','e5','Nfd7','Bd3','c5','c3','Nc6','Ne2','cxd4','cxd4']],
            ['french-classical','French Defense','Classical',         'C00', 'black', ['e4','e6','d4','d5','Nc3','Nf6','Bg5','Be7','e5','Nfd7','Bxe7','Qxe7','f4','a6','Nf3']],

            ['ck-classical',    'Caro-Kann Defense','Classical',      'B10', 'black', ['e4','c6','d4','d5','Nc3','dxe4','Nxe4','Bf5','Ng3','Bg6','h4','h6','Nf3','Nd7','h5','Bh7','Bd3','Bxd3']],
            ['ck-advance',      'Caro-Kann Defense','Advance',        'B10', 'black', ['e4','c6','d4','d5','e5','Bf5','Nf3','e6','Be2','c5','O-O','Nc6','Na3','cxd4']],
            ['ck-exchange',     'Caro-Kann Defense','Exchange',       'B10', 'black', ['e4','c6','d4','d5','exd5','cxd5','Bd3','Nc6','c3','Qc7','Nf3','Bg4','Nbd2','e6']],
            ['ck-fantasy',      'Caro-Kann Defense','Fantasy',        'B10', 'black', ['e4','c6','d4','d5','f3','e6','Nc3','dxe4','fxe4','e5','Nf3','Bg4','Bc4','Nd7']],

            ['scand-modern',    'Scandinavian Defense','Modern (Qd6)','B01', 'black', ['e4','d5','exd5','Nf6','d4','Nxd5','Nf3','g6','c4','Nb6','Nc3','Bg7','Be3','O-O']],
            ['scand-qa5',       'Scandinavian Defense','Classical (Qa5)','B01','black',['e4','d5','exd5','Qxd5','Nc3','Qa5','d4','Nf6','Nf3','Bf5','Bc4','e6','Bd2','Bb4']],
            ['scand-icelandic', 'Scandinavian Defense','Icelandic Gambit','B01','black',['e4','d5','exd5','Nf6','c4','e6','dxe6','Bxe6','Nf3','Nc6','Be2','Bc5','O-O','O-O']],

            ['pirc-classical',  'Pirc Defense',  'Classical',         'B07', 'black', ['e4','d6','d4','Nf6','Nc3','g6','Nf3','Bg7','Be2','O-O','O-O','c6','Rb1']],
            ['pirc-austrian',   'Pirc Defense',  'Austrian Attack',   'B07', 'black', ['e4','d6','d4','Nf6','Nc3','g6','f4','Bg7','Nf3','O-O','Bd3','c5','dxc5','Qa5']],

            ['ale-four',        "Alekhine's Defense",'Four Pawns Attack','B02','black',['e4','Nf6','e5','Nd5','d4','d6','c4','Nb6','f4','dxe5','fxe5','c5','d5','e6','Nc3','exd5']],
            ['ale-modern',      "Alekhine's Defense",'Modern',        'B02', 'black', ['e4','Nf6','e5','Nd5','d4','d6','Nf3','g6','Bc4','Nb6','Bb3','Bg7','Ng5','e6','Nxf7']],

            // ── 1.d4 ───────────────────────────────────────────────────────
            ['qg-accepted',     "Queen's Gambit",'Accepted',          'D06', 'white', ['d4','d5','c4','dxc4','Nf3','Nf6','e3','e6','Bxc4','c5','O-O','a6','Qe2','b5','Bb3']],
            ['qg-declined',     "Queen's Gambit",'Declined',          'D06', 'white', ['d4','d5','c4','e6','Nc3','Nf6','Bg5','Be7','e3','O-O','Nf3','h6','Bh4','b6','Bd3']],
            ['qg-slav',         "Queen's Gambit",'Slav Defense',      'D06', 'white', ['d4','d5','c4','c6','Nf3','Nf6','Nc3','dxc4','a4','Bf5','e3','e6','Bxc4','Bb4']],
            ['qg-semi-slav',    "Queen's Gambit",'Semi-Slav',         'D06', 'white', ['d4','d5','c4','c6','Nf3','Nf6','Nc3','e6','Bg5','h6','Bh4','dxc4','e4','g5','Bg3','b5']],
            ['qg-exchange',     "Queen's Gambit",'Exchange',          'D06', 'white', ['d4','d5','c4','e6','Nc3','Nf6','cxd5','exd5','Bg5','Be7','e3','c6','Bd3','Nbd7','Qc2']],

            ['kid-classical',   "King's Indian Defense",'Classical',  'E60', 'black', ['d4','Nf6','c4','g6','Nc3','Bg7','e4','d6','Nf3','O-O','Be2','e5','O-O','Nc6','d5','Ne7']],
            ['kid-samisch',     "King's Indian Defense",'Sämisch',    'E60', 'black', ['d4','Nf6','c4','g6','Nc3','Bg7','e4','d6','f3','O-O','Be3','e5','d5','Nh5','Qd2','f5']],
            ['kid-4pawn',       "King's Indian Defense",'Four Pawns', 'E60', 'black', ['d4','Nf6','c4','g6','Nc3','Bg7','e4','d6','f4','O-O','Nf3','c5','d5','e6','dxe6','fxe6']],
            ['kid-averbakh',    "King's Indian Defense",'Averbakh',   'E60', 'black', ['d4','Nf6','c4','g6','Nc3','Bg7','e4','d6','Be2','O-O','Bg5','h6','Be3','c5','d5','e6']],

            ['nimzo-classical', 'Nimzo-Indian Defense','Classical',   'E20', 'black', ['d4','Nf6','c4','e6','Nc3','Bb4','Qc2','O-O','a3','Bxc3+','Qxc3','b6','Bg5','Bb7','e3']],
            ['nimzo-rubinstein','Nimzo-Indian Defense','Rubinstein',  'E20', 'black', ['d4','Nf6','c4','e6','Nc3','Bb4','e3','O-O','Bd3','d5','Nf3','c5','O-O','dxc4','Bxc4']],
            ['nimzo-samisch',   'Nimzo-Indian Defense','Sämisch',     'E20', 'black', ['d4','Nf6','c4','e6','Nc3','Bb4','a3','Bxc3+','bxc3','O-O','f3','d5','cxd5','Nxd5','e4']],
            ['nimzo-zucker',    'Nimzo-Indian Defense','Zürich/Huebner','E20','black',['d4','Nf6','c4','e6','Nc3','Bb4','Nf3','c5','g3','cxd4','Nxd4','O-O','Bg2','d5','cxd5']],

            ['qi-main',         "Queen's Indian Defense",'Main Line', 'E12', 'black', ['d4','Nf6','c4','e6','Nf3','b6','g3','Bb7','Bg2','Be7','O-O','O-O','Nc3','Ne4','Qc2','Nxc3']],
            ['qi-4th',          "Queen's Indian Defense",'Petrosian System','E12','black',['d4','Nf6','c4','e6','Nf3','b6','a3','Bb7','Nc3','d5','cxd5','Nxd5','Bd2','Nd7','e4','Nxc3']],

            ['grunfeld-exchange','Grünfeld Defense','Exchange',       'D70', 'black', ['d4','Nf6','c4','g6','Nc3','d5','cxd5','Nxd5','e4','Nxc3','bxc3','Bg7','Bc4','c5','Ne2','O-O']],
            ['grunfeld-russian','Grünfeld Defense','Russian System',  'D70', 'black', ['d4','Nf6','c4','g6','Nc3','d5','Nf3','Bg7','Qb3','dxc4','Qxc4','O-O','e4','Na6','Be2','c5']],
            ['grunfeld-neo',    'Grünfeld Defense','Neo-Grünfeld',    'D70', 'black', ['d4','Nf6','c4','g6','g3','d5','Bg2','Bg7','Nf3','O-O','O-O','dxc4','Na3','c3','bxc3','c5']],

            ['bogo-main',       'Bogo-Indian Defense','Main Line',    'E11', 'black', ['d4','Nf6','c4','e6','Nf3','Bb4+','Bd2','Bxd2+','Qxd2','O-O','Nc3','d5','cxd5','exd5']],
            ['bogo-4th',        'Bogo-Indian Defense','4.Nbd2',       'E11', 'black', ['d4','Nf6','c4','e6','Nf3','Bb4+','Nbd2','b6','a3','Bxd2+','Qxd2','Bb7','e3','O-O','Be2']],

            ['benoni-modern',   'Benoni Defense','Modern Benoni',     'A60', 'black', ['d4','Nf6','c4','c5','d5','e6','Nc3','exd5','cxd5','d6','Nf3','g6','e4','Bg7','Be2','O-O','O-O']],
            ['benoni-benko',    'Benoni Defense','Benko Gambit',      'A60', 'black', ['d4','Nf6','c4','c5','d5','b5','cxb5','a6','bxa6','Bxa6','Nc3','d6','Nf3','g6','g3','Bg7','Bg2','O-O']],

            ['dutch-leningrad', 'Dutch Defense', 'Leningrad',         'A80', 'black', ['d4','f5','g3','Nf6','Bg2','g6','Nf3','Bg7','O-O','O-O','c4','d6','Nc3','Qe8']],
            ['dutch-stonewall', 'Dutch Defense', 'Stonewall',         'A80', 'black', ['d4','f5','c4','Nf6','g3','e6','Bg2','d5','Nf3','c6','O-O','Bd6','b3','O-O','Bb2']],
            ['dutch-classical', 'Dutch Defense', 'Classical',         'A80', 'black', ['d4','f5','c4','Nf6','g3','e6','Bg2','d6','Nf3','Be7','O-O','O-O','Nc3','Nc6','d5']],

            // ── London / English ───────────────────────────────────────────
            ['london-main',     'London System','Main Line',          'D02', 'white', ['d4','d5','Nf3','Nf6','Bf4','e6','e3','Bd6','Bg3','O-O','Nbd2','c5','c3','Nc6','Bd3']],
            ['london-jobava',   'London System','Jobava London',      'D02', 'white', ['d4','d5','Nc3','Nf6','Bf4','e6','e3','Bd6','Bg3','O-O','f3','c5','dxc5','Bxc5','e4']],
            ['london-barry',    'London System','Barry Attack (vs KID)','D02','white',['d4','Nf6','Nf3','g6','Nc3','d5','Bf4','Bg7','e3','O-O','Be2','c5','Ne5','Nc6','Nxc6','bxc6']],

            ['english-symm',    'English Opening','Symmetrical',      'A10', 'white', ['c4','c5','Nf3','Nf6','Nc3','d5','cxd5','Nxd5','g3','Nc6','Bg2','Be7','O-O','O-O','d4']],
            ['english-rev',     'English Opening','Reversed Sicilian','A10', 'white', ['c4','e5','Nc3','Nf6','g3','d5','cxd5','Nxd5','Bg2','Nb6','Nf3','Nc6','O-O','Be7','d3']],
            ['english-hedgehog','English Opening','Hedgehog',         'A10', 'white', ['c4','c5','Nf3','Nf6','Nc3','e6','g3','b6','Bg2','Bb7','O-O','Be7','d4','cxd4','Nxd4','d6']],

            // ── System / Hypermodern ───────────────────────────────────────
            ['catalan-open',    'Catalan Opening','Open Catalan',     'E00', 'white', ['d4','Nf6','c4','e6','g3','d5','Bg2','dxc4','Nf3','Bd7','O-O','c5','Qe2','cxd4','Nxd4','Bc6']],
            ['catalan-closed',  'Catalan Opening','Closed Catalan',   'E00', 'white', ['d4','Nf6','c4','e6','g3','d5','Bg2','Be7','Nf3','O-O','O-O','dxc4','Qc2','a6','a4','Bd7']],

            ['tromp-main',      'Trompowsky Attack','Main',           'A45', 'white', ['d4','Nf6','Bg5','Ne4','Bf4','c5','f3','Qa5+','c3','Nf6','d5','d6','e4','e5']],
            ['tromp-2e6',       'Trompowsky Attack','2...e6',         'A45', 'white', ['d4','Nf6','Bg5','e6','e4','h6','Bxf6','Qxf6','Nc3','d6','Qd2','Nd7','O-O-O','g6','f4']],

            ['bird-main',       "Bird's Opening",'Main',              'A02', 'white', ['f4','d5','Nf3','Nf6','e3','g6','Be2','Bg7','O-O','O-O','d3','c5','c3']],
            ['bird-from',       "Bird's Opening","From's Gambit",     'A02', 'white', ['f4','e5','fxe5','d6','exd6','Bxd6','Nf3','g5','d4','g4','Ne5','Bxe5','dxe5','Qxd1+','Kxd1','Nc6']],

            ['torre-main',      'Torre Attack', 'Main',               'A46', 'white', ['d4','Nf6','Nf3','e6','Bg5','Be7','Nbd2','d5','e3','O-O','Bd3','c5','c3','b6']],
            ['torre-colle',     'Torre Attack', 'Colle-Zukertort',    'A46', 'white', ['d4','d5','Nf3','Nf6','e3','e6','Bd3','c5','O-O','Nc6','b3','Be7','Bb2','O-O','Nbd2','b6']],

            ['modern-main',     'Modern Defense','Main',              'B06', 'black', ['e4','g6','d4','Bg7','Nc3','d6','Nf3','a6','a4','b6','Be2','Bb7','O-O','Nd7']],
            ['modern-avoid',    'Modern Defense','Avertissement',     'B06', 'black', ['e4','g6','d4','Bg7','Nc3','c6','f4','d5','e5','h5','Nf3','Nh6','Ne2','Bg4']],

            ['budapest-fajarowicz','Budapest Gambit','Fajarowicz',    'A52', 'black', ['d4','Nf6','c4','e5','dxe5','Ne4','Nf3','Bb4+','Nbd2','Nc6','a3','Bxd2+','Bxd2','Nxd2']],
            ['budapest-rubinstein','Budapest Gambit','Rubinstein',    'A52', 'black', ['d4','Nf6','c4','e5','dxe5','Ng4','Nf3','Nc6','Bf4','Bb4+','Nbd2','Qe7','e3','Ngxe5','a3','Bxd2+','Nxd2']],
        ];

        foreach ($lines as [$lineId, $openingName, $lineName, $eco, $color, $moves]) {
            OpeningLine::updateOrCreate(
                ['line_id' => $lineId],
                [
                    'opening_name' => $openingName,
                    'line_name'    => $lineName,
                    'eco'          => $eco,
                    'color'        => $color,
                    'moves'        => $moves,
                ]
            );
        }
    }
}
