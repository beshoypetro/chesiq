<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\Course;
use App\Models\Module;
use App\Models\Track;
use Illuminate\Database\Seeder;

/**
 * Full academy curriculum — four tracks, each with 2 courses, 3–4 modules per
 * course, and 3–5 activities per module.  Mix of lesson_markdown, quiz,
 * puzzle_set, and endgame_set activities with real instructional content.
 *
 * Idempotent: every record uses updateOrCreate keyed on slugs, so re-running
 * is safe.  This seeder also creates the four tracks (replacing the stub
 * AcademyTrackSeeder — DatabaseSeeder calls only this one).
 */
class AcademyCurriculumSeeder extends Seeder
{
    // ──────────────────────────────────────────────────────────────────────────
    // ENTRY POINT
    // ──────────────────────────────────────────────────────────────────────────

    public function run(): void
    {
        $this->seedFoundations();
        $this->seedImprover();
        $this->seedClub();
        $this->seedTournament();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // TRACK 1 — FOUNDATIONS (400–1000)
    // ──────────────────────────────────────────────────────────────────────────

    private function seedFoundations(): void
    {
        $track = Track::updateOrCreate(
            ['slug' => 'foundations'],
            [
                'name'          => 'Foundations',
                'elo_min'       => 400,
                'elo_max'       => 1000,
                'display_order' => 1,
                'description'   => 'Basic tactical patterns, K+P endgames, opening principles, board vision.',
                'published'     => true,
            ]
        );

        // ── Course 1: Tactical Foundations ──────────────────────────────────
        $c1 = Course::updateOrCreate(
            ['track_id' => $track->id, 'slug' => 'tactical-foundations'],
            [
                'name'          => 'Tactical Foundations',
                'description'   => 'Learn the core one- and two-move tactical motifs every chess player must know.',
                'display_order' => 1,
            ]
        );

        // Module 1.1 — Forks & Double Attacks
        $m = Module::updateOrCreate(
            ['course_id' => $c1->id, 'slug' => 'forks-double-attacks'],
            [
                'name'          => 'Forks & Double Attacks',
                'overview'      => 'A fork attacks two enemy pieces at once with a single move. Master knight, pawn, and queen forks to win material.',
                'display_order' => 1,
                'published'     => true,
            ]
        );
        $this->activities($m->id, [
            [
                'type'          => 'lesson_markdown',
                'title'         => 'What is a Fork?',
                'display_order' => 1,
                'config' => [
                    // Trainer-narrated beats — the lesson is *spoken* by the trainer,
                    // board-forward, one idea at a time. (The markdown below is kept
                    // only as a text fallback / teacher context.)
                    'beats' => [
                        [
                            'say' => "Let's learn the fork — one move that attacks two pieces at the same time. See my knight sitting on e5? Keep your eye on it.",
                            'fen' => '3q3k/6pp/8/4N3/8/8/5PPP/6K1 w - - 0 1',
                            'orientation' => 'white',
                        ],
                        [
                            'say' => "Watch this. The knight jumps to f7 — and that's check. The black king is in trouble in the corner.",
                            'move' => 'Nf7+',
                        ],
                        [
                            'say' => "Here's the magic: from f7, that same knight is also attacking the queen on d8. Two pieces, one move. THAT is a fork.",
                        ],
                        [
                            'say' => "Black has to answer the check first — the king steps over to g8. But notice, the queen is still hanging.",
                            'move' => 'Kg8',
                        ],
                        [
                            'say' => "So I just take it. The queen comes off the board, completely free. That's a winning fork.",
                            'move' => 'Nxd8',
                        ],
                        [
                            'say' => "That's the whole idea: attack two things at once, and your opponent can only save one. Knights are the best at this — that L-shape jump is almost impossible to block. Now go win some material.",
                        ],
                    ],
                    'markdown' => <<<MD
# What is a Fork?

A **fork** is a tactic where one piece attacks two or more enemy pieces simultaneously, forcing your opponent to lose material because they can only save one at a time.

## The Knight Fork

Knights are the best forking pieces because they move in an L-shape that no other piece can easily block or interpose. Place your knight on a square that attacks the opponent's king and queen at the same time — this is called a **royal fork**.

**Key square:** The square your knight jumps to is called the *fork square*. Always look for a fork square that is safe (not guarded by an enemy pawn) and attacks high-value targets.

Step through this royal fork — press **Next** to watch it happen:

:::playthrough
caption: Nf7+ attacks the king on h8 AND the queen on d8. The king must move (Kg8), then Nxd8 wins the queen for free.
orientation: white
fen: 3q3k/6pp/8/4N3/8/8/5PPP/6K1 w - - 0 1
moves: Nf7+ Kg8 Nxd8
:::

## Pawn Forks

Pawns fork pieces on the two diagonal squares directly ahead of them. Advancing a pawn to attack two pieces is a very common winning trick at all levels.

**Example:** White plays e4–e5, forking a black knight on d6 and bishop on f6. Black loses a piece.

## Queen Forks

The queen can fork from any direction, but be careful — after the fork the queen may herself be attacked.

## How to Spot Forks

1. Look at all your opponent's pieces grouped together or close to each other.
2. Ask: is there a square my knight (or pawn) can reach that attacks two of them at once?
3. Check that the fork square is safe before playing.

**Mini exercise:** Set up White Ke1, Nc3, Black Ke8, Qd6, Ra8. Find the knight fork! (Answer: Nd5, attacking the black queen and the rook via the a8 square if you manoeuvre further — or more directly, look for Ne4–d6.)
MD
                ],
            ],
            [
                'type'          => 'puzzle_set',
                'title'         => 'Fork Practice (Foundations)',
                'display_order' => 2,
                'config' => [
                    'theme'    => 'fork',
                    'elo_band' => 'foundations',
                    'count'    => 10,
                ],
            ],
            [
                'type'          => 'quiz',
                'title'         => 'Forks Quiz',
                'display_order' => 3,
                'config' => ['questions' => [
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'What makes the knight the best forking piece?',
                        'options'       => [
                            'It is the most powerful piece on the board',
                            'Its L-shaped move cannot be blocked by intervening pieces',
                            'It can move to any square in one go',
                            'It always attacks the king',
                        ],
                        'correct_index' => 1,
                        'explanation'   => 'The knight\'s unique L-shaped leap means no piece can interpose between the knight and its target, making forks impossible to block.',
                    ],
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'White has a knight on e5. Black has a king on g6 and a rook on c6. What should White play?',
                        'fen'           => '8/8/2r3k1/4N3/8/8/8/4K3 w - - 0 1',
                        'options'       => [
                            'Nc4 — retreat the knight to safety',
                            'Nf3 — prepare a future attack',
                            'The position is already a fork — White wins the rook',
                            'Nd7 — attack the king',
                        ],
                        'correct_index' => 2,
                        'explanation'   => 'The knight on e5 already forks the king on g6 and the rook on c6. After Black moves the king, White takes the rook.',
                    ],
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'Which of these is NOT a type of fork?',
                        'options'       => [
                            'Knight fork',
                            'Pawn fork',
                            'Queen fork',
                            'Fianchetto',
                        ],
                        'correct_index' => 3,
                        'explanation'   => 'A fianchetto is an opening setup where a bishop is developed to b2 or g2. It has nothing to do with forks.',
                    ],
                ]],
            ],
        ]);

        // Module 1.2 — Pins & Skewers
        $m = Module::updateOrCreate(
            ['course_id' => $c1->id, 'slug' => 'pins-skewers'],
            [
                'name'          => 'Pins & Skewers',
                'overview'      => 'Pins immobilise pieces by threatening something more valuable behind them; skewers work in reverse. Both win material.',
                'display_order' => 2,
                'published'     => true,
            ]
        );
        $this->activities($m->id, [
            [
                'type'          => 'lesson_markdown',
                'title'         => 'Pins and Skewers Explained',
                'display_order' => 1,
                'config' => ['markdown' => <<<MD
# Pins and Skewers

## The Pin

A **pin** occurs when a sliding piece (bishop, rook, or queen) attacks an enemy piece that is shielding a more valuable piece behind it. The pinned piece cannot move without exposing the valuable piece to capture.

**Absolute pin:** The piece behind is the king. The pinned piece literally cannot move (it would leave the king in check).

**Relative pin:** The piece behind is valuable (usually a queen or rook), but not the king. The pinned piece *can* legally move, but doing so loses material.

### How to exploit a pin:

1. Identify the pinned piece.
2. Attack the pinned piece with more of your own pieces.
3. If your opponent cannot unpin (by interposing or moving the piece behind), you win material.

**Example:** White bishop on b2, Black knight on f6 pinned against the Black king on g7. White plays Ng5 attacking the pinned knight. Black cannot move the knight (exposes the king), so the knight falls.

## The Skewer

A **skewer** is the reverse of a pin. The sliding piece attacks the more valuable piece first; when it moves, it exposes the less valuable piece behind it.

**Example:** White rook on e1, Black king on e8, Black rook on e5. White plays Re1–e8+. The Black king must move; the White rook then takes the Black rook on e5.

## Tips

- Bishops and rooks are the most natural pinning pieces.
- Always check if an enemy piece is already lined up with its own king.
- Skewers often involve the king and a valuable piece on the same file, rank, or diagonal.
MD
                ],
            ],
            [
                'type'          => 'puzzle_set',
                'title'         => 'Pin Puzzles',
                'display_order' => 2,
                'config' => [
                    'theme'    => 'pin',
                    'elo_band' => 'foundations',
                    'count'    => 10,
                ],
            ],
            [
                'type'          => 'puzzle_set',
                'title'         => 'Skewer Puzzles',
                'display_order' => 3,
                'config' => [
                    'theme'    => 'skewer',
                    'elo_band' => 'foundations',
                    'count'    => 8,
                ],
            ],
            [
                'type'          => 'quiz',
                'title'         => 'Pins & Skewers Quiz',
                'display_order' => 4,
                'config' => ['questions' => [
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'What is an "absolute pin"?',
                        'options'       => [
                            'A pin so strong the opponent must lose material',
                            'A pin where the piece behind the pinned piece is the king',
                            'A pin executed by a queen',
                            'A pin that also threatens mate',
                        ],
                        'correct_index' => 1,
                        'explanation'   => 'An absolute pin is one where the king sits behind the pinned piece. The pinned piece cannot legally move because that would expose the king to check.',
                    ],
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'How does a skewer differ from a pin?',
                        'options'       => [
                            'A skewer only works with knights',
                            'In a skewer, the valuable piece is in front; moving it exposes the piece behind',
                            'A skewer is only possible on the first rank',
                            'There is no difference — they are the same tactic',
                        ],
                        'correct_index' => 1,
                        'explanation'   => 'In a pin, the less valuable piece is in front protecting the more valuable one. In a skewer, the more valuable piece is in front; it must move, allowing the attacker to capture what was behind it.',
                    ],
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'Which pieces can execute a pin?',
                        'options'       => [
                            'Only the queen',
                            'Knight and king',
                            'Bishop, rook, and queen',
                            'Any piece',
                        ],
                        'correct_index' => 2,
                        'explanation'   => 'Pins require a sliding piece that attacks along a line. Bishops, rooks, and queens are the only pieces that slide, so only they can create pins.',
                    ],
                ]],
            ],
        ]);

        // Module 1.3 — Mate in One
        $m = Module::updateOrCreate(
            ['course_id' => $c1->id, 'slug' => 'mate-in-one'],
            [
                'name'          => 'Checkmate in One',
                'overview'      => 'Train the most fundamental skill: seeing a forced checkmate in a single move across all common mating patterns.',
                'display_order' => 3,
                'published'     => true,
            ]
        );
        $this->activities($m->id, [
            [
                'type'          => 'lesson_markdown',
                'title'         => 'Reading the Board for Mate',
                'display_order' => 1,
                'config' => ['markdown' => <<<MD
# Reading the Board for Mate in One

Finding checkmate in one move is the most important tactical skill to develop first. Before calculating anything elaborate, always ask: **"Can I give checkmate right now?"**

## The Checklist

To checkmate the king in one move you need:
1. **Attack the king** — your move must give check.
2. **The king cannot move** — every escape square is covered or blocked.
3. **The check cannot be blocked or captured** — there is no legal defence.

## Common Patterns

### Back-rank Mate
The enemy king is trapped behind its own pawns on the first or eighth rank. One sliding piece (rook or queen) delivers check on the back rank; the pawns prevent the king from escaping.

### Smothered Mate Setup
The king is surrounded by its own pieces. A knight delivers check and the king cannot move because friendly pieces block every square.

### Queen + King Battery
Your queen, supported by another piece (bishop, knight, or the king itself), delivers a contact check from which the king cannot escape.

## Practical Advice

- Before every move, glance at the opponent's king position. How many escape squares does it have?
- Whenever you deliver check, mentally walk through all the king's possible moves.
- Eliminating king escape squares (with your own pieces *and* your opponent's pieces trapping their king) is the secret.
MD
                ],
            ],
            [
                'type'          => 'puzzle_set',
                'title'         => 'Mate in One — Practice Set',
                'display_order' => 2,
                'config' => [
                    'theme'    => 'mateIn1',
                    'elo_band' => 'foundations',
                    'count'    => 12,
                ],
            ],
            [
                'type'          => 'puzzle_set',
                'title'         => 'Back-rank Mate Puzzles',
                'display_order' => 3,
                'config' => [
                    'theme'    => 'backRankMate',
                    'elo_band' => 'foundations',
                    'count'    => 8,
                ],
            ],
        ]);

        // ── Course 2: Endgame Essentials ────────────────────────────────────
        $c2 = Course::updateOrCreate(
            ['track_id' => $track->id, 'slug' => 'endgame-essentials'],
            [
                'name'          => 'Endgame Essentials',
                'description'   => 'King and pawn endgames, the opposition, and basic checkmates every beginner must master.',
                'display_order' => 2,
            ]
        );

        // Module 2.1 — King Activity
        $m = Module::updateOrCreate(
            ['course_id' => $c2->id, 'slug' => 'king-activity'],
            [
                'name'          => 'Activating the King',
                'overview'      => 'In the endgame the king transforms from a liability into a powerful fighting piece. Learn to centralise it fast.',
                'display_order' => 1,
                'published'     => true,
            ]
        );
        $this->activities($m->id, [
            [
                'type'          => 'lesson_markdown',
                'title'         => 'The King as an Endgame Weapon',
                'display_order' => 1,
                'config' => ['markdown' => <<<MD
# The King as an Endgame Weapon

One of the biggest mistakes beginners make in endgames is leaving the king passive. In the middlegame the king hides; in the endgame it must **fight**.

## Why the King Matters

With fewer pieces on the board, there is less danger of a sudden attack. The king becomes a powerful piece capable of controlling key squares, supporting pawns, and chasing down enemy pawns.

## Centralise the King

The king is strongest in the centre (d4, d5, e4, e5) because from there it can reach any part of the board in the fewest moves. Count the number of moves the king needs to reach any corner — a centralised king requires at most three moves to get to any corner; a king stuck in the corner may need six or seven.

## The Opposition

When two kings face each other on the same rank, file, or diagonal with one square between them, the player whose turn it is *does not have the opposition* — they must give way. The player whose turn it is to move is in **zugzwang** (forced to make a disadvantageous move).

**Direct opposition:** Kings on e4 and e6. If it is Black's move, White has the opposition, and White can advance.

## King and Pawn vs. King

The most fundamental endgame:
- If the attacking king can reach in front of the pawn, the position is usually a win.
- If the defending king reaches the queening square before the pawn can be supported, it is usually a draw.

**Rule of the square:** Draw a diagonal square from the pawn to the queening square. If the defending king can step *into* that square on its move, it will catch the pawn — it is a draw.
MD
                ],
            ],
            [
                'type'          => 'endgame_set',
                'title'         => 'King & Pawn Endgames',
                'display_order' => 2,
                'config' => [
                    'category' => 'pawn',
                    'count'    => 5,
                ],
            ],
            [
                'type'          => 'quiz',
                'title'         => 'King Activity Quiz',
                'display_order' => 3,
                'config' => ['questions' => [
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'Why should you centralise your king in the endgame?',
                        'options'       => [
                            'To castle more quickly',
                            'Because it is safest in the centre',
                            'A centralised king reaches any part of the board in the fewest moves',
                            'To prevent the opponent from castling',
                        ],
                        'correct_index' => 2,
                        'explanation'   => 'From the centre, the king can reach any sector of the board efficiently. A king stuck on the edge or in the corner may be too slow to support its pawns or stop enemy pawns.',
                    ],
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'Kings are on e4 and e6 with White to move. Who has the opposition?',
                        'fen'           => '8/8/4k3/8/4K3/8/8/8 w - - 0 1',
                        'options'       => [
                            'White has the opposition',
                            'Black has the opposition',
                            'Neither — it is not a direct opposition',
                            'Both players share the opposition',
                        ],
                        'correct_index' => 1,
                        'explanation'   => 'When it is White\'s turn to move, Black holds the opposition. White must move the king, giving Black the ability to advance or maintain opposition.',
                    ],
                ]],
            ],
        ]);

        // Module 2.2 — Basic Checkmates
        $m = Module::updateOrCreate(
            ['course_id' => $c2->id, 'slug' => 'basic-checkmates'],
            [
                'name'          => 'Basic Checkmates',
                'overview'      => 'Queen + King and Rook + King checkmates are mandatory technique. Learn the exact procedure to force the enemy king to the edge.',
                'display_order' => 2,
                'published'     => true,
            ]
        );
        $this->activities($m->id, [
            [
                'type'          => 'lesson_markdown',
                'title'         => 'Queen + King vs. King',
                'display_order' => 1,
                'config' => ['markdown' => <<<MD
# Queen + King vs. Lone King

This is one of the first theoretical checkmates every chess player learns. With correct technique you should be able to force checkmate in under 10 moves from any starting position.

## The Principle: Drive the King to the Edge

A lone king cannot be checkmated in the centre of the board. Your first task is to **restrict the king** by reducing the number of squares it can access, until it is forced to the edge or corner.

## Step-by-Step Method

### 1. Box the King In
Use your queen to cut off ranks or files. Think of it as drawing an imaginary box around the enemy king and shrinking the box one rank/file at a time.

**Example:** Black king on e5. Place your queen on d3. Now the king cannot go to d4, e4, f4, or beyond. The king is restricted to the upper part of the board.

### 2. Bring Your King In
The queen alone cannot force checkmate (it would result in stalemate). Your king must come close to support. Bring it to the centre.

### 3. Deliver the Final Check
Once the enemy king is in a corner or on the edge, the queen delivers the final check with the king nearby to cover escape squares.

## Stalemate Warning!

The most common mistake is stalemating the opponent. **Never** leave the enemy king with no legal moves unless it is also in check.

Always ask before delivering check: can the king move to a safe square? If the answer is no *and* it is not in check, you have given stalemate — a draw!

## Practice

Set up a White queen and king against a lone Black king. Try to force checkmate in under 10 moves without stalemate.
MD
                ],
            ],
            [
                'type'          => 'endgame_set',
                'title'         => 'Queen Endgame Practice',
                'display_order' => 2,
                'config' => [
                    'category' => 'queen',
                    'count'    => 5,
                ],
            ],
            [
                'type'          => 'endgame_set',
                'title'         => 'Rook Endgame Practice',
                'display_order' => 3,
                'config' => [
                    'category' => 'rook',
                    'count'    => 5,
                ],
            ],
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // TRACK 2 — IMPROVER (1000–1500)
    // ──────────────────────────────────────────────────────────────────────────

    private function seedImprover(): void
    {
        $track = Track::updateOrCreate(
            ['slug' => 'improver'],
            [
                'name'          => 'Improver',
                'elo_min'       => 1000,
                'elo_max'       => 1500,
                'display_order' => 2,
                'description'   => 'Intermediate tactics, basic pawn structures, R endgames, repertoire intro.',
                'published'     => true,
            ]
        );

        // ── Course 1: Intermediate Tactics ──────────────────────────────────
        $c1 = Course::updateOrCreate(
            ['track_id' => $track->id, 'slug' => 'intermediate-tactics'],
            [
                'name'          => 'Intermediate Tactics',
                'description'   => 'Two-move combinations, discovered attacks, deflections, and back-rank threats.',
                'display_order' => 1,
            ]
        );

        // Module 1.1 — Discovered Attacks
        $m = Module::updateOrCreate(
            ['course_id' => $c1->id, 'slug' => 'discovered-attacks'],
            [
                'name'          => 'Discovered Attacks',
                'overview'      => 'Moving one piece unleashes a hidden attack from another — one of the most powerful two-move combinations in chess.',
                'display_order' => 1,
                'published'     => true,
            ]
        );
        $this->activities($m->id, [
            [
                'type'          => 'lesson_markdown',
                'title'         => 'The Discovered Attack',
                'display_order' => 1,
                'config' => ['markdown' => <<<MD
# Discovered Attacks

A **discovered attack** occurs when you move one piece out of the way to reveal an attack by a piece behind it. The moved piece often makes a threat of its own, creating a double threat that your opponent cannot meet with one move.

## Why Discovered Attacks are Powerful

Your opponent must deal with **two threats simultaneously**:
1. The threat from the piece that moved.
2. The threat from the piece that was unmasked (the *battery piece*).

Because chess only allows one move per turn, at least one threat will succeed.

## Discovered Check

The most forceful version: moving the front piece reveals a check by the battery piece. The opponent must deal with the check, allowing your front piece to do anything — capture material, promote, or reposition.

**Example:** White bishop on b2, White rook on e2 masked by a knight on e4. White plays Ne4–c5+!! The rook on e2 now checks the king on e8. Meanwhile the knight on c5 attacks the Black queen on d7. Black must handle the check; White captures the queen next move.

## The Double Check

The ultimate discovered attack: **both** the moved piece and the battery piece give check simultaneously. The defending king must *move* — it cannot capture or block two checkers. Double checks often lead to forced mate.

## How to Find Discovered Attacks

1. Look for a "battery" — two of your pieces on the same line (file, rank, diagonal) with an enemy piece at the end.
2. Ask: what if I move the front piece? Does the back piece gain an attack?
3. Can the front piece make a *second* threat as it moves?
MD
                ],
            ],
            [
                'type'          => 'puzzle_set',
                'title'         => 'Discovered Attack Puzzles',
                'display_order' => 2,
                'config' => [
                    'theme'    => 'discoveredAttack',
                    'elo_band' => 'improver',
                    'count'    => 10,
                ],
            ],
            [
                'type'          => 'quiz',
                'title'         => 'Discovered Attack Quiz',
                'display_order' => 3,
                'config' => ['questions' => [
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'In a discovered attack, which piece creates the primary threat?',
                        'options'       => [
                            'The battery piece that was hidden behind',
                            'The piece that moves away (the front piece)',
                            'Both pieces create threats simultaneously',
                            'The king',
                        ],
                        'correct_index' => 2,
                        'explanation'   => 'In a discovered attack, both pieces create threats. The front piece moves to a new square and often attacks or captures, while the battery piece is unmasked and reveals its attack. The opponent usually cannot deal with both.',
                    ],
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'What is a "double check"?',
                        'options'       => [
                            'Checking the king twice in a row',
                            'Both the moved piece and the battery piece give check simultaneously',
                            'A check that wins the queen',
                            'Checking with two pawns',
                        ],
                        'correct_index' => 1,
                        'explanation'   => 'A double check is when a discovered attack results in both the moving piece and the revealed battery piece giving check at the same time. The only legal response is to move the king.',
                    ],
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'Why is a double check impossible to block?',
                        'options'       => [
                            'Because it involves a knight',
                            'Because you cannot interpose between two simultaneous attackers with a single move',
                            'Because it always leads to checkmate',
                            'Because the king cannot move during a double check',
                        ],
                        'correct_index' => 1,
                        'explanation'   => 'A single interposing piece can only block one line at a time. With two simultaneous checks from different directions, no single interposition can cover both, so the king must move.',
                    ],
                ]],
            ],
        ]);

        // Module 1.2 — Deflection & Decoy
        $m = Module::updateOrCreate(
            ['course_id' => $c1->id, 'slug' => 'deflection-decoy'],
            [
                'name'          => 'Deflection & Decoy',
                'overview'      => 'Force a key defender away from its post (deflection) or lure a piece to a bad square (decoy) to unlock winning combinations.',
                'display_order' => 2,
                'published'     => true,
            ]
        );
        $this->activities($m->id, [
            [
                'type'          => 'lesson_markdown',
                'title'         => 'Deflection and Decoy Tactics',
                'display_order' => 1,
                'config' => ['markdown' => <<<MD
# Deflection and Decoy

## Deflection

A **deflection** sacrifices material (or makes a threat) to pull a key defensive piece away from the square or line it is guarding. Once the defender is deflected, you can execute the follow-up threat.

**How to spot a deflection:**
1. Identify a winning follow-up (mate, capture of a large piece).
2. Identify the one piece preventing that follow-up.
3. Make a sacrifice or threat that *forces* that defender to move.

**Example:** Black rook on f8 guards the back rank. White plays Rxf8+!! Black must recapture (Kxf8 or Rxf8). Either way the back-rank guard is gone, and White delivers Qd8#.

## Decoy

A **decoy** (also called a "lure") sacrifices material to place an enemy piece on a *specific bad square*, after which you win with a second tactic.

**Example:** White queen on d5, Black king on g8. White plays Qd8+!! luring the king to d8. Then Rd1+ wins the king (or delivers mate in the next move).

The difference: deflection *removes* a defender; decoy *places* a piece on a square you want it on.

## Why Players Miss These Tactics

Both motifs involve giving something up first — a concept that feels counterintuitive. Train yourself to ask: "What if I sacrifice here?" before dismissing a position as drawn.

## Practice

Look for positions where one enemy piece is holding the position together. Anything that removes that piece is worth calculating.
MD
                ],
            ],
            [
                'type'          => 'puzzle_set',
                'title'         => 'Deflection Puzzles',
                'display_order' => 2,
                'config' => [
                    'theme'    => 'deflection',
                    'elo_band' => 'improver',
                    'count'    => 10,
                ],
            ],
            [
                'type'          => 'puzzle_set',
                'title'         => 'Sacrifice Combinations',
                'display_order' => 3,
                'config' => [
                    'theme'    => 'sacrifice',
                    'elo_band' => 'improver',
                    'count'    => 10,
                ],
            ],
        ]);

        // Module 1.3 — Mate in Two
        $m = Module::updateOrCreate(
            ['course_id' => $c1->id, 'slug' => 'mate-in-two'],
            [
                'name'          => 'Checkmate in Two',
                'overview'      => 'Step up from single-move mates to two-move forced sequences, building visualisation of one move ahead.',
                'display_order' => 3,
                'published'     => true,
            ]
        );
        $this->activities($m->id, [
            [
                'type'          => 'lesson_markdown',
                'title'         => 'Thinking Two Moves Ahead',
                'display_order' => 1,
                'config' => ['markdown' => <<<MD
# Thinking Two Moves Ahead: Mate in Two

A mate-in-two problem requires you to find a move such that, *whatever* your opponent replies, you have a forced checkmate on the next move.

## The Method

### Step 1 — Find the Key Move
The first move is often a quiet move (no check, no capture) that sets up an unstoppable threat. This is called the **key move**.

### Step 2 — Check All Replies
After your key move, your opponent has multiple options. For *every* possible reply, you must have a checkmate answer ready.

If even one reply escapes checkmate, your first move was wrong — go back and try another.

## Common Patterns

**Zugzwang:** The key move places the opponent in a situation where any move they make allows checkmate. The opponent is in *zugzwang* — any move worsens their position.

**Dual threats:** Your key move threatens two different checkmates. The opponent can stop one but not both.

**Clearance:** The key move clears a line for a battery that delivers check on the second move.

## Visualisation Exercise

Before calculating, try to *see* the final checkmate position in your mind. Work backwards: where must the king be? Which of your pieces deliver the check? Which cover the king's escape squares? Then find the move that forces the king there.

This reverse-engineering technique is used by masters to solve combinations efficiently.
MD
                ],
            ],
            [
                'type'          => 'puzzle_set',
                'title'         => 'Mate in Two Puzzles',
                'display_order' => 2,
                'config' => [
                    'theme'    => 'mateIn2',
                    'elo_band' => 'improver',
                    'count'    => 12,
                ],
            ],
        ]);

        // ── Course 2: Pawn Structures & Plans ───────────────────────────────
        $c2 = Course::updateOrCreate(
            ['track_id' => $track->id, 'slug' => 'pawn-structures-plans'],
            [
                'name'          => 'Pawn Structures & Plans',
                'description'   => 'Understand how pawn structures dictate piece plans: isolated pawns, doubled pawns, passed pawns, and rook endgame technique.',
                'display_order' => 2,
            ]
        );

        // Module 2.1 — Isolated and Doubled Pawns
        $m = Module::updateOrCreate(
            ['course_id' => $c2->id, 'slug' => 'isolated-doubled-pawns'],
            [
                'name'          => 'Isolated & Doubled Pawns',
                'overview'      => 'Isolated and doubled pawns are structural weaknesses. Learn to create them in your opponent\'s camp and defend your own.',
                'display_order' => 1,
                'published'     => true,
            ]
        );
        $this->activities($m->id, [
            [
                'type'          => 'lesson_markdown',
                'title'         => 'Pawn Weaknesses: Isolated and Doubled Pawns',
                'display_order' => 1,
                'config' => ['markdown' => <<<MD
# Pawn Weaknesses: Isolated and Doubled Pawns

## The Isolated Pawn

An **isolated pawn** has no friendly pawns on the adjacent files. It cannot be defended by another pawn and must be protected by pieces — which ties those pieces to passive defence.

**Why isolated pawns are weak:**
- They require permanent piece protection.
- The square directly in front of an isolated pawn is a permanent outpost for the opponent's pieces (especially knights).
- In the endgame, the opposing king can attack the isolated pawn directly.

**The d-pawn isolani** (an isolated pawn on d4 or d5) is the most common weak pawn type arising from many openings (Queen's Gambit, French Defence, Caro-Kann). It gives the player some compensation (space, active pieces, open files) in the middlegame but is hard to defend in the endgame.

**Plan against an isolated pawn:**
1. Blockade it on the square in front with a knight.
2. Exchange pieces to enter an endgame where the pawn is a target.
3. Attack it with rooks and/or the king.

## Doubled Pawns

**Doubled pawns** are two pawns of the same colour on the same file. They cannot defend each other and limit the mobility of other pieces.

**Why doubled pawns are weak:**
- Only one of the two doubled pawns can ever advance (the other is blocked).
- They create open or semi-open files for the opponent's rooks.
- The rearmost doubled pawn often becomes a target.

**When doubled pawns are acceptable:** If they open a file for your rooks, provide key central control, or give you the bishop pair as compensation, doubled pawns can be playable.
MD
                ],
            ],
            [
                'type'          => 'quiz',
                'title'         => 'Pawn Structure Quiz',
                'display_order' => 2,
                'config' => ['questions' => [
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'What is the ideal piece to blockade an isolated d-pawn?',
                        'options'       => [
                            'A rook',
                            'A bishop',
                            'A knight',
                            'The king',
                        ],
                        'correct_index' => 2,
                        'explanation'   => 'A knight on d5 (or d4) in front of an isolated pawn cannot be attacked by other pawns. It sits on a permanent outpost, controls key squares, and prevents the isolated pawn from advancing.',
                    ],
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'Which is the main long-term plan against an opponent who has an isolated pawn?',
                        'options'       => [
                            'Trade all the pieces to reach a king and pawn endgame',
                            'Attack on the kingside immediately',
                            'Blockade the pawn with a piece, then reduce to an endgame where it is a target',
                            'Advance your own pawns on the opposite wing',
                        ],
                        'correct_index' => 2,
                        'explanation'   => 'Against an isolated pawn, the classic plan is: blockade (place a piece in front so it cannot advance), exchange pieces (the isolated pawn becomes weaker with fewer defenders), then win the pawn in the endgame.',
                    ],
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'When might doubled pawns be acceptable?',
                        'options'       => [
                            'Never — doubled pawns are always losing',
                            'If they open a useful file or grant the bishop pair',
                            'Only if they are on the c-file',
                            'Only in the endgame',
                        ],
                        'correct_index' => 1,
                        'explanation'   => 'Doubled pawns can be acceptable if the trade that creates them also opens an important file for your rooks or grants you the bishop pair. Compensation must outweigh the structural weakness.',
                    ],
                ]],
            ],
        ]);

        // Module 2.2 — Passed Pawns & Rook Endgames
        $m = Module::updateOrCreate(
            ['course_id' => $c2->id, 'slug' => 'passed-pawns-rook-endgames'],
            [
                'name'          => 'Passed Pawns & Rook Endgames',
                'overview'      => 'A passed pawn is a powerful asset in the endgame. Learn the Lucena and Philidor positions — the two essential rook endgame blueprints.',
                'display_order' => 2,
                'published'     => true,
            ]
        );
        $this->activities($m->id, [
            [
                'type'          => 'lesson_markdown',
                'title'         => 'Passed Pawns and the Rook Endgame',
                'display_order' => 1,
                'config' => ['markdown' => <<<MD
# Passed Pawns and Rook Endgames

## The Passed Pawn

A **passed pawn** has no enemy pawns in front of it on its file or on adjacent files. It can advance to promotion without being blocked by a pawn.

> "A passed pawn must be pushed!" — Paul Morphy / chess proverb

**Why passed pawns win games:**
- They tie down enemy pieces to stop promotion.
- They can be supported by the king and rooks to force promotion.
- A passed pawn supported by a rook from behind (*a passer*) is one of the most dangerous weapons in the endgame.

## Rook Behind the Passer

A rook belongs **behind** a passed pawn — its own or the opponent's. A rook behind a passed pawn grows stronger as the pawn advances (more board to control behind it). A rook in front of a passer is cramped and passive.

## The Lucena Position — Winning with Rook + Pawn

The Lucena position is the "blueprint win" for Rook + Pawn vs. Rook. The attacking king is in front of the pawn; the defending rook is cutting the attacking king off on the file.

**Technique: Bridge-building.** The winning side brings the rook to the 4th rank to shield the king from checks. The rook "builds a bridge" so the king can escape the checks and support pawn promotion.

## The Philidor Position — Drawing with Rook

The Philidor position is the "blueprint draw." The defending rook sits on the 6th rank, cutting the attacking king off. When the pawn advances, the rook switches to give checks from behind (the "Philidor check").

**Key rule:** Keep the rook on the 6th rank *until* the pawn advances to the 6th rank. Then switch to checking from behind.

These two positions are the foundation of all rook endgame theory.
MD
                ],
            ],
            [
                'type'          => 'endgame_set',
                'title'         => 'Rook Endgame Positions',
                'display_order' => 2,
                'config' => [
                    'category' => 'rook',
                    'count'    => 5,
                ],
            ],
            [
                'type'          => 'quiz',
                'title'         => 'Rook Endgame Quiz',
                'display_order' => 3,
                'config' => ['questions' => [
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'Where should a rook be placed relative to a passed pawn?',
                        'options'       => [
                            'In front of the passed pawn to stop it',
                            'Behind the passed pawn — own or enemy',
                            'On a completely different file',
                            'On the 7th rank always',
                        ],
                        'correct_index' => 1,
                        'explanation'   => 'A rook behind a passed pawn gains strength as the pawn advances. This applies whether it is your own passer (to support it) or the enemy\'s (to restrain it).',
                    ],
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'In the Philidor position, when should the defending rook switch from the 6th rank to giving checks from behind?',
                        'options'       => [
                            'Immediately, at the start of the position',
                            'When the attacking pawn advances to the 6th rank',
                            'When the defending king is in the corner',
                            'Only after the pawn queens',
                        ],
                        'correct_index' => 1,
                        'explanation'   => 'The Philidor technique: hold the 6th rank to cut off the attacking king. Once the pawn reaches the 6th rank, the rook switches to giving checks from behind — the king cannot shelter from the checks with the pawn blocking.',
                    ],
                ]],
            ],
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // TRACK 3 — CLUB (1500–1900)
    // ──────────────────────────────────────────────────────────────────────────

    private function seedClub(): void
    {
        $track = Track::updateOrCreate(
            ['slug' => 'club'],
            [
                'name'          => 'Club Player',
                'elo_min'       => 1500,
                'elo_max'       => 1900,
                'display_order' => 3,
                'description'   => 'Advanced tactics, IQP/hanging pawns, master game studies, full repertoire build.',
                'published'     => true,
            ]
        );

        // ── Course 1: Advanced Tactics & Calculation ─────────────────────────
        $c1 = Course::updateOrCreate(
            ['track_id' => $track->id, 'slug' => 'advanced-tactics-calculation'],
            [
                'name'          => 'Advanced Tactics & Calculation',
                'description'   => 'Multi-move combinations, piece sacrifices, and the WOODPECKER method for tactical training.',
                'display_order' => 1,
            ]
        );

        // Module 1.1 — The Calculation Method
        $m = Module::updateOrCreate(
            ['course_id' => $c1->id, 'slug' => 'calculation-method'],
            [
                'name'          => 'The Calculation Method',
                'overview'      => 'Learn a structured process for calculating complex variations without blundering: candidate moves, forcing sequences, and tree pruning.',
                'display_order' => 1,
                'published'     => true,
            ]
        );
        $this->activities($m->id, [
            [
                'type'          => 'lesson_markdown',
                'title'         => 'Systematic Calculation: A Framework',
                'display_order' => 1,
                'config' => ['markdown' => <<<MD
# Systematic Calculation: A Framework

At the club level, the gap between players who blunder and players who find combinations is mostly about having a *system* for calculation. Here is a proven framework.

## Step 1 — Assess Before You Calculate

Before diving into variations, take 10–15 seconds to ask:
- What does my opponent's last move threaten?
- What are my pieces doing (or not doing)?
- Are there any forcing moves available (checks, captures, threats)?

## Step 2 — Generate Candidate Moves

Do not calculate only the first move that comes to mind. Generate 2–4 **candidate moves** — the moves worth calculating at all. Typically these are:
- Checks
- Captures
- Moves that create a serious threat

Then assess each candidate systematically.

## Step 3 — Calculate the Most Forcing Line First

Start with checks and forced captures — "forcing moves" narrow the tree of possibilities because your opponent's responses are limited. This keeps the calculation tree manageable.

## Step 4 — Blunder-Check Your Final Move

Before playing, pause and ask: *"What is my opponent's best reply?"* This simple question catches the majority of tactical blunders.

## Step 5 — The Quiet Move Rule

The strongest moves in many positions are quiet moves — not check, not capture, but moves that create an unstoppable threat. After calculating forced sequences, check if there is a quiet move that wins even more cleanly.

## Common Calculation Errors

- **Premature pruning:** Dismissing a line after only 1–2 moves because it looks bad. Always calculate to a quiet position.
- **Counting errors:** Miscounting material after a series of exchanges.
- **Forgetting intermediate moves:** Your opponent may have an *in-between move* (zwischenzug) between your expected sequence of moves.
MD
                ],
            ],
            [
                'type'          => 'puzzle_set',
                'title'         => 'Calculation Drill — Sacrifices',
                'display_order' => 2,
                'config' => [
                    'theme'    => 'sacrifice',
                    'elo_band' => 'club',
                    'count'    => 10,
                ],
            ],
            [
                'type'          => 'puzzle_set',
                'title'         => 'Discovered Attack Combinations',
                'display_order' => 3,
                'config' => [
                    'theme'    => 'discoveredAttack',
                    'elo_band' => 'club',
                    'count'    => 10,
                ],
            ],
            [
                'type'          => 'quiz',
                'title'         => 'Calculation Quiz',
                'display_order' => 4,
                'config' => ['questions' => [
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'What is the recommended first step when faced with a complex position?',
                        'options'       => [
                            'Immediately calculate the longest forcing line you can find',
                            'Assess the position — check what the opponent threatens and what forces are available',
                            'Always play a check first to make the opponent react',
                            'Develop the least active piece',
                        ],
                        'correct_index' => 1,
                        'explanation'   => 'Jumping straight into calculation without first assessing the position leads to missing the opponent\'s threats and wasted calculation time. A quick assessment of the critical features of the position should precede any calculation.',
                    ],
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'What is a "zwischenzug"?',
                        'options'       => [
                            'A German word for checkmate in two',
                            'An in-between move that changes the outcome of an expected exchange sequence',
                            'A pawn sacrifice on the 5th rank',
                            'A rook manoeuvre along the 7th rank',
                        ],
                        'correct_index' => 1,
                        'explanation'   => 'Zwischenzug (German: "in-between move") is an intermediate move inserted into what appears to be a forced sequence. Instead of recapturing, a player makes a more important move first, often completely changing the evaluation.',
                    ],
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'Why should you calculate the most forcing moves first?',
                        'options'       => [
                            'Because checks always win',
                            'Forcing moves limit opponent replies, keeping the calculation tree small and manageable',
                            'They are always the best moves',
                            'Because captures refill your material count',
                        ],
                        'correct_index' => 1,
                        'explanation'   => 'Forcing moves (checks, captures, threats) constrain your opponent\'s options. With fewer legal replies to consider, the calculation tree shrinks dramatically, reducing errors and saving time.',
                    ],
                ]],
            ],
        ]);

        // Module 1.2 — Piece Sacrifices
        $m = Module::updateOrCreate(
            ['course_id' => $c1->id, 'slug' => 'piece-sacrifices'],
            [
                'name'          => 'Piece Sacrifices',
                'overview'      => 'Exchange sacrifices, piece sacrifices for initiative, and the "Greek Gift" bishop sacrifice — recognise and execute these patterns.',
                'display_order' => 2,
                'published'     => true,
            ]
        );
        $this->activities($m->id, [
            [
                'type'          => 'lesson_markdown',
                'title'         => 'The Art of the Piece Sacrifice',
                'display_order' => 1,
                'config' => ['markdown' => <<<MD
# The Art of the Piece Sacrifice

At the club level, the ability to sacrifice material for a lasting attack or positional advantage separates improving players from stagnating ones.

## The Greek Gift (Classic Bishop Sacrifice)

The **Greek Gift** is Bxh7+ (or ...Bxh2+), sacrificing the bishop to strip away the castled king's pawn cover.

**Preconditions for Bxh7+:**
1. Bishop on d3 (or e4/c2) with an unobstructed diagonal to h7.
2. Knight on f3 ready to come to g5/e5.
3. Queen ready to activate — usually to h5.
4. The h7 pawn is only guarded by the king (not another piece).
5. The g6 square is not available as a king escape (or if it is, you have covered it).

**After Bxh7+ Kxh7, Ng5+ Kg8 (or Kg6), Qh5** — White threatens Qh7# and Qxf7#. Black must find an exact defensive resource or face inevitable checkmate.

## The Exchange Sacrifice

Sacrificing a rook for a bishop or knight (losing the "exchange") is often correct when:
- The rook is passive and the minor piece is very strong.
- The sacrifice destroys the opponent's pawn structure.
- The sacrifice removes the key defender of the king.

Tigran Petrosian built an entire career on exchange sacrifices for positional advantage.

## Positional Sacrifices

Sometimes you give up material not for a forced win but for **long-term positional compensation**:
- A permanent knight outpost
- Open files for rooks
- A dangerous passed pawn
- The bishop pair in an open position

Always ask: what do I get for my sacrifice? Identify the *concrete* compensation before sacrificing.
MD
                ],
            ],
            [
                'type'          => 'puzzle_set',
                'title'         => 'Sacrifice Pattern Puzzles',
                'display_order' => 2,
                'config' => [
                    'theme'    => 'sacrifice',
                    'elo_band' => 'club',
                    'count'    => 12,
                ],
            ],
        ]);

        // Module 1.3 — Complex Endgames
        $m = Module::updateOrCreate(
            ['course_id' => $c1->id, 'slug' => 'complex-endgames'],
            [
                'name'          => 'Complex Endgames',
                'overview'      => 'Knight and bishop endgames, wrong-coloured bishop draws, and R+P vs. R technical wins for club players.',
                'display_order' => 3,
                'published'     => true,
            ]
        );
        $this->activities($m->id, [
            [
                'type'          => 'lesson_markdown',
                'title'         => 'Bishop Endgames: Same vs. Opposite Colour',
                'display_order' => 1,
                'config' => ['markdown' => <<<MD
# Bishop Endgames: Same vs. Opposite Colour

## Same-Colour Bishop Endgames

When both players have bishops on the *same colour* of squares, the endgame is generally easier to win for the stronger side. The attacking bishop can target the opponent's pawns directly, and the defending bishop must protect all the weak squares.

**Plan:**
- Place your pawns on squares *opposite* to the colour of your bishop (your bishop attacks the colour squares the pawns do not stand on).
- Advance the king to support passed pawns.
- Create a passed pawn and support it.

## Opposite-Colour Bishop Endgames (OCB)

Opposite-colour bishop endgames are notorious for their **drawing tendencies**. The defending bishop controls squares the attacker's bishop cannot touch, creating an impenetrable fortress.

**Why OCBs draw:**
- The attacking bishop cannot attack pawns defended by the opposing bishop.
- The defending king plus bishop cover one colour completely; the attacker cannot penetrate.

**Classic OCB draw:** Two extra pawns are often insufficient to win in OCB endgames if the pawns are blockaded on the bishop's colour.

**When OCBs can still be won:**
- The pawns are on *both* colours and cannot all be protected.
- There is a passed pawn the defending bishop cannot stop.
- The attacking king penetrates to decisive action.

## The Wrong-Rook Pawn

A rook pawn (a- or h-pawn) is a draw if the bishop is the *wrong colour* (cannot control the queening square). The defending king simply sits in the corner; the pawn can never push the king out.
MD
                ],
            ],
            [
                'type'          => 'endgame_set',
                'title'         => 'Bishop Endgame Positions',
                'display_order' => 2,
                'config' => [
                    'category' => 'bishop',
                    'count'    => 5,
                ],
            ],
            [
                'type'          => 'endgame_set',
                'title'         => 'Knight Endgame Positions',
                'display_order' => 3,
                'config' => [
                    'category' => 'knight',
                    'count'    => 5,
                ],
            ],
        ]);

        // ── Course 2: Master Game Studies ────────────────────────────────────
        $c2 = Course::updateOrCreate(
            ['track_id' => $track->id, 'slug' => 'master-game-studies'],
            [
                'name'          => 'Master Game Studies',
                'description'   => 'Study complete games from the great masters to understand how plans, piece coordination, and endgame technique combine.',
                'display_order' => 2,
            ]
        );

        // Module 2.1 — Attack on the King
        $m = Module::updateOrCreate(
            ['course_id' => $c2->id, 'slug' => 'attack-on-the-king'],
            [
                'name'          => 'Attacking the Castled King',
                'overview'      => 'Learn the universal ingredients of a successful kingside attack through Morphy, Tal, and Kasparov games.',
                'display_order' => 1,
                'published'     => true,
            ]
        );
        $this->activities($m->id, [
            [
                'type'          => 'lesson_markdown',
                'title'         => 'Ingredients of a Winning Attack',
                'display_order' => 1,
                'config' => ['markdown' => <<<MD
# Ingredients of a Winning Attack

The greatest attacking players in history — Morphy, Tal, Kasparov — did not simply "play wild" chess. Their attacks were based on concrete principles. Understanding these principles lets you generate your own attacks.

## 1. Open Lines to the King

Attacks need highways. Open files and diagonals pointing at the enemy king are the roads your pieces travel. Pawn breaks (g4–g5, h4–h5 against a kingside-castled king) open lines.

## 2. Piece Coordination

An attack is not one piece charging; it is a coordinated assault. Ask how many of your pieces participate in the attack. Three or more pieces pointed at the king are usually enough to deliver decisive threats even with sound defence.

## 3. Time — the Development Factor

Morphy's greatest lesson: **if you are ahead in development, attack immediately, before the opponent can catch up.** Every move spent on defence by your opponent is a move they do not use to activate pieces.

## 4. Pawn Shelter Removal

The castled king is protected by the h6/g6/f6 (or h3/g3/f3 for Black) pawn shelter. Break or trade away those pawns to expose the king. The Greek Gift sacrifice (Bxh7+) is the classic example.

## 5. The Lure Sacrifice

Sometimes you need to sacrifice material to lure the king out of its shelter into the open board where your pieces dominate. Tal perfected this — sacrificing unsoundly but knowing his opponents would falter under practical pressure.

## Recognising an Attack is Justified

Ask three questions before launching:
1. Am I ahead in development?
2. Are lines (files/diagonals) open or openable toward the king?
3. Can I bring 3+ pieces into the attack before my opponent consolidates?

If yes to all three: attack aggressively. If no: improve your pieces first.
MD
                ],
            ],
            [
                'type'          => 'quiz',
                'title'         => 'Attacking Chess Quiz',
                'display_order' => 2,
                'config' => ['questions' => [
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'What is the primary prerequisite before launching a kingside attack?',
                        'options'       => [
                            'Your queen must be on the kingside',
                            'Open or openable lines toward the king, plus piece coordination',
                            'The opponent\'s queen must be on the queenside',
                            'You must have more material than the opponent',
                        ],
                        'correct_index' => 1,
                        'explanation'   => 'Attacks require lanes of communication. Without open files or diagonals pointing at the king, your pieces cannot penetrate. Piece coordination (multiple pieces aiming at the king) is equally essential.',
                    ],
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'Paul Morphy\'s greatest attacking principle was:',
                        'options'       => [
                            'Always castle queenside',
                            'Attack immediately if you are ahead in development',
                            'Trade queens at the first opportunity',
                            'Advance pawns before developing pieces',
                        ],
                        'correct_index' => 1,
                        'explanation'   => 'Morphy\'s games demonstrate repeatedly that a lead in development is temporary. If you do not convert it into an attack immediately, your opponent catches up and the advantage evaporates.',
                    ],
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'How many pieces should ideally participate in a decisive king attack?',
                        'options'       => [
                            'One — concentrate power on a single piece',
                            'Two — queen and one supporting piece',
                            'Three or more — coordinated assault',
                            'All eight pieces',
                        ],
                        'correct_index' => 2,
                        'explanation'   => 'With three or more pieces directed at the king, even sound defensive play usually crumbles. Two pieces can be neutralised; three or more create threats that cannot all be met simultaneously.',
                    ],
                ]],
            ],
        ]);

        // Module 2.2 — Positional Play
        $m = Module::updateOrCreate(
            ['course_id' => $c2->id, 'slug' => 'positional-play'],
            [
                'name'          => 'Positional Chess & Outposts',
                'overview'      => 'Outposts, piece activity, and the principle of two weaknesses — learn how to win positional games systematically.',
                'display_order' => 2,
                'published'     => true,
            ]
        );
        $this->activities($m->id, [
            [
                'type'          => 'lesson_markdown',
                'title'         => 'Outposts, Activity, and the Two Weaknesses',
                'display_order' => 1,
                'config' => ['markdown' => <<<MD
# Outposts, Activity, and the Principle of Two Weaknesses

## Outposts

An **outpost** is a square that:
1. Is in the opponent's half of the board.
2. Cannot be attacked by an enemy pawn (either there is no enemy pawn on adjacent files, or such pawns have already passed the outpost square).
3. Can be occupied by one of your pieces (usually a knight).

A knight on an outpost is often worth as much as a rook because it can never be driven away by pawns and ties down the opponent's pieces to passive defence.

**Creating outposts:** You often create outposts by exchanging pawns — trading your d-pawn for the opponent's c-pawn leaves a permanent outpost on d5 for your knight.

## Piece Activity

The activity of a piece — the number of squares it controls and its ability to reach key areas — is often more important than its nominal value.

A bishop restricted by its own pawns ("a bad bishop") may be worth less than a knight sitting on a beautiful outpost. Think about your *worst* piece and find a way to improve it every few moves.

## The Principle of Two Weaknesses

**Definition:** If your opponent has only one weakness, they can concentrate all their pieces on defending it. To win, create a *second* weakness — force them to defend in two places simultaneously.

**Classic plan:**
1. Fix one weakness (e.g. an isolated pawn) by attacking it.
2. As the opponent rushes pieces to defend, break through on the other wing.
3. The opponent cannot defend both — one weakness falls.

Capablanca and Karpov were masters of this technique.
MD
                ],
            ],
            [
                'type'          => 'puzzle_set',
                'title'         => 'Positional Crushing Combinations',
                'display_order' => 2,
                'config' => [
                    'theme'    => 'crushing',
                    'elo_band' => 'club',
                    'count'    => 8,
                ],
            ],
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // TRACK 4 — TOURNAMENT (1900–2400)
    // ──────────────────────────────────────────────────────────────────────────

    private function seedTournament(): void
    {
        $track = Track::updateOrCreate(
            ['slug' => 'tournament'],
            [
                'name'          => 'Tournament',
                'elo_min'       => 1900,
                'elo_max'       => 2400,
                'display_order' => 4,
                'description'   => 'Deep calculation, prophylaxis, complex endgames, time management.',
                'published'     => true,
            ]
        );

        // ── Course 1: Deep Calculation & Prophylaxis ─────────────────────────
        $c1 = Course::updateOrCreate(
            ['track_id' => $track->id, 'slug' => 'deep-calculation-prophylaxis'],
            [
                'name'          => 'Deep Calculation & Prophylaxis',
                'description'   => 'Master long forced variations, prophylactic thinking, and the art of stopping the opponent\'s plans.',
                'display_order' => 1,
            ]
        );

        // Module 1.1 — Prophylaxis
        $m = Module::updateOrCreate(
            ['course_id' => $c1->id, 'slug' => 'prophylaxis'],
            [
                'name'          => 'Prophylactic Thinking',
                'overview'      => 'Think like your opponent — identify their best plans and prevent them before they start. Prophylaxis separates masters from club players.',
                'display_order' => 1,
                'published'     => true,
            ]
        );
        $this->activities($m->id, [
            [
                'type'          => 'lesson_markdown',
                'title'         => 'The Art of Prophylaxis',
                'display_order' => 1,
                'config' => ['markdown' => <<<MD
# The Art of Prophylaxis

**Prophylaxis** (from the Greek: prevention) is thinking about what your opponent *wants to do* and making a move that prevents their plans while improving your own position.

Petrosian was the supreme prophet of this technique. He would stop attacks before they began, often making moves that seemed quiet and even passive but were in fact making the opponent's life impossible.

## The Mental Process

Before every move, ask:
1. **What is my opponent's best plan/move in this position?**
2. **How can I prevent it while improving my own position?**

This is different from reactive play ("I see a threat, I block it"). Prophylaxis is *proactive* — you see a threat that does not yet exist and extinguish it.

## Types of Prophylactic Moves

**Restraint:** Preventing a pawn break with a pawn move (h3 to prevent ...Ng4, a3 to prevent ...Nb4) before the opponent plays it.

**Piece repositioning:** Moving a piece to a square where it prevents the opponent's key plan while contributing to your own.

**Exchanging the dangerous piece:** Trading off the opponent's most active piece before it becomes a problem.

## Prophylaxis vs. Passive Defence

There is an important distinction between *passive* defence (responding to threats) and *active* prophylaxis (preventing threats before they materialise). Passive defence wastes tempo. Prophylaxis maintains the initiative.

## Practical Application

In complex positions, add a "prophylaxis check" to your thinking routine:
- Before playing your candidate move: "If I play this, what is my opponent's best response?"
- Then: "Is there a way to make my move AND prevent that response?"

The best moves often do both simultaneously.
MD
                ],
            ],
            [
                'type'          => 'puzzle_set',
                'title'         => 'Crushing Combinations (Tournament)',
                'display_order' => 2,
                'config' => [
                    'theme'    => 'crushing',
                    'elo_band' => 'tournament',
                    'count'    => 10,
                ],
            ],
            [
                'type'          => 'quiz',
                'title'         => 'Prophylaxis & Deep Calculation Quiz',
                'display_order' => 3,
                'config' => ['questions' => [
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'What distinguishes prophylaxis from passive defence?',
                        'options'       => [
                            'Prophylaxis always involves a pawn move',
                            'Prophylaxis prevents threats before they materialise; passive defence reacts to existing threats',
                            'Prophylaxis only applies to king safety',
                            'Passive defence is a type of prophylaxis',
                        ],
                        'correct_index' => 1,
                        'explanation'   => 'Passive defence responds after the threat appears, often losing tempo. Prophylaxis sees the threat coming and stops it in advance while making a useful move, maintaining the initiative.',
                    ],
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'Which grandmaster was most famous for prophylactic play?',
                        'options'       => [
                            'Mikhail Tal',
                            'Paul Morphy',
                            'Tigran Petrosian',
                            'Magnus Carlsen',
                        ],
                        'correct_index' => 2,
                        'explanation'   => 'Tigran Petrosian (World Champion 1963–1969) was renowned for his prophylactic style. He would extinguish counterplay before it began, frustrating his opponents and winning from positions that appeared completely equal.',
                    ],
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'What should you do before playing your candidate move in a complex position?',
                        'options'       => [
                            'Count all material on the board',
                            'Ask what your opponent\'s best response would be after your move',
                            'Always look for a forcing move first',
                            'Check if you can castle',
                        ],
                        'correct_index' => 1,
                        'explanation'   => 'The "opponent\'s best reply" check catches blunders and reveals prophylactic opportunities. If your candidate move allows a strong reply, either modify it or find a prophylactic move that prevents the reply.',
                    ],
                ]],
            ],
        ]);

        // Module 1.2 — Complex Endgame Technique
        $m = Module::updateOrCreate(
            ['course_id' => $c1->id, 'slug' => 'complex-endgame-technique'],
            [
                'name'          => 'Complex Endgame Technique',
                'overview'      => 'Queen vs. Rook endgames, R+B vs. R, and the art of converting technical advantages under tournament conditions.',
                'display_order' => 2,
                'published'     => true,
            ]
        );
        $this->activities($m->id, [
            [
                'type'          => 'lesson_markdown',
                'title'         => 'Converting Technical Advantages',
                'display_order' => 1,
                'config' => ['markdown' => <<<MD
# Converting Technical Advantages

Many tournament games are lost not in sharp complications but in the endgame — through inaccurate technique that allows the opponent to escape a theoretically lost position.

## The Principle of Precision

In technical endgames you cannot afford "good enough" — only the *best* move will win. The margin of error is far smaller than in the middlegame.

**Practice rule:** After finding your move in an endgame, spend an extra 30 seconds searching for a way it could fail. This saves half-points repeatedly in tournaments.

## Queen vs. Rook

This is theoretically a win for the queen in most cases, but it requires precise technique:
- Force the rook away from the king with checks and threats.
- Approach your king to help trap the rook and king together.
- Deliver a fork or skewer to win the rook.

The Philidor / Lolli positions are key reference points.

## Rook + Bishop vs. Rook

This endgame is nominally a draw with best play, but it is *extremely difficult* for the defending side at tournament level. The winning technique (Cochrane/Lolli method) is to create a *Philidor-style mating net* combining the rook and bishop.

## Rook Endgames — The 7th Rank

A rook on the 7th rank is worth at least one pawn because it attacks all the opponent's unmoved pawns simultaneously. Two rooks on the 7th rank are often decisive ("pigs on the 7th").

## Time Management

Complex endgames demand time. A mistake many players make is entering time pressure and then making speed moves. Instead:
1. **Pre-move planning:** Use opponent's time to plan your moves.
2. **Reserve time:** Do not spend excessive time calculating in the middlegame if you foresee a technical endgame — you will need that time later.
3. **Know your theory:** Familiarity with common endgame positions means you spend less time working them out at the board.
MD
                ],
            ],
            [
                'type'          => 'endgame_set',
                'title'         => 'Rook Endgame Masterclass',
                'display_order' => 2,
                'config' => [
                    'category' => 'rook',
                    'count'    => 5,
                ],
            ],
            [
                'type'          => 'endgame_set',
                'title'         => 'Queen Endgame Positions',
                'display_order' => 3,
                'config' => [
                    'category' => 'queen',
                    'count'    => 5,
                ],
            ],
        ]);

        // Module 1.3 — Opening Preparation
        $m = Module::updateOrCreate(
            ['course_id' => $c1->id, 'slug' => 'opening-preparation'],
            [
                'name'          => 'Opening Preparation & Repertoire',
                'overview'      => 'Build and memorise a complete tournament-level repertoire, handle transpositions, and prepare specific opening novelties.',
                'display_order' => 3,
                'published'     => true,
            ]
        );
        $this->activities($m->id, [
            [
                'type'          => 'lesson_markdown',
                'title'         => 'Building a Tournament Repertoire',
                'display_order' => 1,
                'config' => ['markdown' => <<<MD
# Building a Tournament Repertoire

At the tournament level, reliable opening preparation is a weapon, not just a formality. You should aim for complete coverage — answers to everything your opponent can play — rather than knowing one or two systems deeply.

## What "Complete" Means

A complete repertoire means:
- A clear answer to every major first move (1.e4 or 1.d4 from White; a prepared system against each from Black).
- Coverage of the top 3–4 variations in each main line.
- Awareness of key move-order tricks and transpositional possibilities.

You do *not* need to memorise 25 moves deep — knowing the correct plans and key ideas to move 15 is sufficient for most tournament games.

## Repertoire Building Principles

**1. Play what you understand.** A line you understand deeply will serve you better than a fashionable but complex line you have memorised without comprehension.

**2. Limit your openings.** Playing the same structure repeatedly means your middlegame and endgame knowledge compounds. Jumping between 10 different systems means starting from scratch each time.

**3. Study the endgames of your openings.** Most players study variations to move 15 and stop. The players who outplay you in the endgame are the ones who studied the typical endgames arising from *your opening*.

**4. Update regularly.** Opening theory evolves. Review your main lines after major tournaments (World Championship, Candidates) — the top players reveal improvements.

## Handling Deviations

Your opponent will often deviate from the main lines early. Prepare for this:
- Understand the *purpose* of each move in your opening, not just the sequence.
- When the opponent plays an unusual move, ask: "What does this achieve? What does it give up?"
- A useful rule: if the opponent plays an early deviation, *over-develop and equalise* rather than punishing immediately.
MD
                ],
            ],
            [
                'type'          => 'quiz',
                'title'         => 'Opening Preparation Quiz',
                'display_order' => 2,
                'config' => ['questions' => [
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'How deep should your opening preparation typically go for tournament play?',
                        'options'       => [
                            '3–5 moves is enough for any level',
                            'Memorise 25+ moves in all main lines',
                            'Know plans and key ideas to around move 15, with deeper prep in main lines',
                            'Opening theory does not matter — just understand chess',
                        ],
                        'correct_index' => 2,
                        'explanation'   => 'For tournament play, knowing the key plans and ideas to around move 15 is the practical standard. Deep memorisation (25+ moves) is useful in specific main lines where engines have found important novelties, but broad positional understanding is the foundation.',
                    ],
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'What is the most important factor when choosing an opening repertoire?',
                        'options'       => [
                            'It must be used by the current World Champion',
                            'It must give an objective advantage by force',
                            'You understand the positions and middlegame plans deeply',
                            'It must lead to very sharp positions',
                        ],
                        'correct_index' => 2,
                        'explanation'   => 'Deep understanding beats memorisation. A player who understands their opening\'s plans, structures, and typical endgames will outperform someone who has memorised moves without comprehension.',
                    ],
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'When your opponent plays an early deviation from theory, the safest approach is usually:',
                        'options'       => [
                            'Immediately punish with a complex sacrifice',
                            'Over-develop and comfortably equalise before seeking advantages',
                            'Mirror their moves symmetrically',
                            'Transpose to a completely different opening',
                        ],
                        'correct_index' => 1,
                        'explanation'   => 'Early deviations from theory often aim to take you out of preparation. The safest response is solid development and equality, then outplay in the middlegame/endgame where your preparation and understanding matter most.',
                    ],
                ]],
            ],
        ]);

        // ── Course 2: Strategic Masterclass ─────────────────────────────────
        $c2 = Course::updateOrCreate(
            ['track_id' => $track->id, 'slug' => 'strategic-masterclass'],
            [
                'name'          => 'Strategic Masterclass',
                'description'   => 'Imbalances, long-term planning, and the psychological dimension of over-the-board play.',
                'display_order' => 2,
            ]
        );

        // Module 2.1 — Imbalances
        $m = Module::updateOrCreate(
            ['course_id' => $c2->id, 'slug' => 'imbalances'],
            [
                'name'          => 'Chess Imbalances',
                'overview'      => 'Every position has imbalances — material, structural, or dynamic. Learn to identify and exploit them as the basis of all strategic planning.',
                'display_order' => 1,
                'published'     => true,
            ]
        );
        $this->activities($m->id, [
            [
                'type'          => 'lesson_markdown',
                'title'         => 'Understanding Chess Imbalances',
                'display_order' => 1,
                'config' => ['markdown' => <<<MD
# Understanding Chess Imbalances

Jeremy Silman's "imbalance" concept is the clearest framework for strategic planning. Every position has imbalances — non-symmetrical features — and your plan should exploit your imbalances while neutralising your opponent's.

## The Seven Imbalances

1. **Superior minor piece** — a bishop in an open position vs. a knight; a knight on a strong outpost vs. a bad bishop.
2. **Pawn structure** — passed pawns, isolated pawns, doubled pawns, pawn chains, open files.
3. **Space** — one player controls more territory; their pieces have more room to manoeuvre.
4. **Material** — uneven piece counts; extra pawns; exchange differences.
5. **Control of key files/squares** — a rook on an open file; a knight on a permanent outpost.
6. **Lead in development** — one side is ahead in getting pieces into play.
7. **King safety** — one king is more exposed; this is often the overriding factor.

## How to Plan Using Imbalances

1. List your imbalances (good and bad).
2. List your opponent's imbalances.
3. Your plan should:
   - **Maximise** your best imbalance.
   - **Negate** your opponent's best imbalance.

**Example:** You have a knight on d5 (superior minor piece), the opponent has a bishop pair (also a minor piece imbalance). Your plan: keep the position closed or semi-closed to limit the bishops; use the knight as a permanent anchor.

## Dynamic vs. Static Imbalances

- **Static imbalances** (passed pawns, structural weaknesses) remain relevant for many moves. Plan around them long-term.
- **Dynamic imbalances** (development lead, initiative, king safety) are temporary. Convert them quickly or they evaporate.
MD
                ],
            ],
            [
                'type'          => 'quiz',
                'title'         => 'Imbalances Quiz',
                'display_order' => 2,
                'config' => ['questions' => [
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'Which of the following is a "dynamic" imbalance that must be exploited quickly?',
                        'options'       => [
                            'A passed pawn',
                            'A lead in development',
                            'An isolated pawn weakness',
                            'The bishop pair',
                        ],
                        'correct_index' => 1,
                        'explanation'   => 'A lead in development is temporary — the opponent catches up over time. It must be converted into an attack or material advantage immediately. Structural imbalances like passed pawns or isolated pawns are static and remain relevant longer.',
                    ],
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'You have a knight on a strong outpost d5; your opponent has the bishop pair in an open position. What is your strategic plan?',
                        'options'       => [
                            'Open the position further to activate your knight',
                            'Keep the position closed or semi-closed to limit the bishops',
                            'Trade your knight for one of the bishops immediately',
                            'Attack on the kingside with pawns',
                        ],
                        'correct_index' => 1,
                        'explanation'   => 'A knight on a strong outpost thrives in closed or semi-closed positions where its stability compensates for the limited range. Bishops become dominant in open positions. By keeping things closed, you negate their imbalance and maximise yours.',
                    ],
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'How many imbalances does Silman\'s framework identify?',
                        'options'       => ['Four', 'Five', 'Seven', 'Nine'],
                        'correct_index' => 2,
                        'explanation'   => 'Silman\'s framework identifies seven imbalances: superior minor piece, pawn structure, space, material, control of key files/squares, lead in development, and king safety. Mastering all seven gives you a complete strategic vocabulary.',
                    ],
                ]],
            ],
        ]);

        // Module 2.2 — Time Management & Psychology
        $m = Module::updateOrCreate(
            ['course_id' => $c2->id, 'slug' => 'time-management-psychology'],
            [
                'name'          => 'Time Management & Tournament Psychology',
                'overview'      => 'Manage the clock intelligently, handle time pressure, and understand the psychological factors that decide tight tournament games.',
                'display_order' => 2,
                'published'     => true,
            ]
        );
        $this->activities($m->id, [
            [
                'type'          => 'lesson_markdown',
                'title'         => 'The Clock as a Weapon',
                'display_order' => 1,
                'config' => ['markdown' => <<<MD
# The Clock as a Weapon: Time Management in Tournament Chess

At the tournament level, clock management is as important as chess skill. Many games at the 1900–2400 level are decided not by who plays better chess but by who manages their time better.

## Time Distribution Principles

**Spend time on critical decisions.** Not every move deserves equal thought. Routine moves (development, king safety, obvious recaptures) can be played in under 30 seconds. Complex decisions (sacrifices, structural changes, endgame transitions) may warrant 10–15 minutes.

**The danger zone: move 15–25.** This is where many players enter time pressure. The opening is over (less theory needed), but the position is complex (more calculation needed). Budget extra time for this phase.

**The 10-minute rule.** Never enter the final 30 moves with fewer than 10 minutes (for a standard time control). If you are below this threshold early, you have spent too long somewhere.

## Using Your Opponent's Time

While your opponent thinks:
1. Identify what they are likely considering.
2. Work out your reply in advance.
3. Calculate the main lines of the position *before* it is your turn.

This "thinking on your opponent's time" effectively doubles your calculation time per move.

## Handling Time Pressure

When short of time:
1. **Play confidently.** Hesitation and second-guessing waste time.
2. **Follow the plan.** If you planned an attack, execute it. Do not switch plans in time pressure.
3. **Look for forcing moves.** Checks and captures narrow the opponent's options, simplifying your decisions.
4. **Know the positions.** Familiarity with endgame theory and tactical patterns lets you play quickly by recognition, not calculation.

## Psychology

The tournament is not just between you and the position — it is between you and the opponent.
- **Play the board, not the rating.** Do not be intimidated by higher-rated players; do not be complacent against lower-rated ones.
- **Manage emotions.** Anger, anxiety, and overconfidence all lead to blunders. Develop a pre-move routine that resets your emotional state.
- **Never resign too early.** At the tournament level, many lost positions are drawn or even won through defensive resources and time pressure. Stay in the game.
MD
                ],
            ],
            [
                'type'          => 'quiz',
                'title'         => 'Tournament Psychology Quiz',
                'display_order' => 2,
                'config' => ['questions' => [
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'Which phase of the game typically causes the most time pressure for tournament players?',
                        'options'       => [
                            'Moves 1–10 (opening)',
                            'Moves 15–25 (late opening / early middlegame)',
                            'Moves 40–50 (endgame)',
                            'The final 5 moves before time control',
                        ],
                        'correct_index' => 1,
                        'explanation'   => 'Moves 15–25 are the "danger zone" where opening theory runs out but the position is still complex. Players spend excess time here and enter the middlegame in time pressure. Budget this phase carefully.',
                    ],
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'What should you do while your opponent is thinking?',
                        'options'       => [
                            'Rest and conserve mental energy',
                            'Think about unrelated things to stay fresh',
                            'Identify likely opponent moves and calculate your replies in advance',
                            'Always make your move immediately when they move',
                        ],
                        'correct_index' => 2,
                        'explanation'   => 'Your opponent\'s thinking time is your most valuable resource. Use it to anticipate their moves and pre-calculate replies. This effectively doubles your calculation time per move without touching the clock.',
                    ],
                    [
                        'kind'          => 'multiple_choice',
                        'prompt'        => 'In time pressure, which type of move is safest to play quickly?',
                        'options'       => [
                            'Complex positional pawn advances',
                            'Forcing moves — checks and captures',
                            'Quiet prophylactic moves',
                            'King moves',
                        ],
                        'correct_index' => 1,
                        'explanation'   => 'In time pressure, forcing moves (checks, captures, direct threats) are safest because they limit the opponent\'s options, reducing the decisions you both face next move. Complex positional moves require more evaluation time that you do not have.',
                    ],
                ]],
            ],
            [
                'type'          => 'puzzle_set',
                'title'         => 'Tournament-Level Combinations',
                'display_order' => 3,
                'config' => [
                    'theme'    => 'sacrifice',
                    'elo_band' => 'tournament',
                    'count'    => 10,
                ],
            ],
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // HELPER
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Upsert a list of activities for a module.
     *
     * @param  int                   $moduleId
     * @param  array<int, array<string, mixed>>  $items
     */
    private function activities(int $moduleId, array $items): void
    {
        foreach ($items as $item) {
            Activity::updateOrCreate(
                [
                    'module_id' => $moduleId,
                    'type'      => $item['type'],
                    'title'     => $item['title'],
                ],
                [
                    'config'        => $item['config'],
                    'display_order' => $item['display_order'],
                    'published'     => true,
                ]
            );
        }
    }
}
