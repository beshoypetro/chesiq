<?php

namespace App\Services;

use App\Models\Game;
use App\Models\MoveAnalysis;
use App\Models\UserFailurePattern;
use Illuminate\Support\Carbon;

class FailurePatternDetector
{
    public const PATTERN_KINDS = [
        'hung_piece',
        'missed_tactic',
        'time_pressure_collapse',
        'opening_deviation',
        'endgame_technique_failure',
        'calculation_error',
    ];

    /**
     * Inspect a single analyzed game and merge findings into user_failure_patterns.
     * Idempotent — re-running for the same game increments counters once per pattern.
     */
    public function analyzeGame(Game $game): array
    {
        if (! $game->user_id || ! $game->analyzed_at) {
            return [];
        }

        $userColor = $game->user_color;
        $moves = $game->moveAnalyses()->orderBy('move_number')->get();

        $found = [];

        foreach ($moves as $m) {
            // Only the user's moves matter for detection.
            if ($m->color !== $userColor) continue;

            $kinds = $this->classifyMistake($m, $game);

            foreach ($kinds as $kind) {
                $key = $kind . '|' . ($game->eco_code ?? '?') . '|' . $this->phaseFor((int) $m->move_number);
                $found[$key] = $found[$key] ?? [
                    'kind' => $kind,
                    'eco' => $game->eco_code,
                    'phase' => $this->phaseFor((int) $m->move_number),
                    'sample_move' => $m->move_san,
                    'sample_best' => $m->best_move_san,
                    'cp_loss' => $m->cp_loss,
                ];
            }
        }

        // Detect opening deviation (uses repertoire_deviation_ply if present).
        if ($game->repertoire_deviation_ply !== null && $game->repertoire_deviation_ply > 0 && $game->repertoire_deviation_ply <= 12) {
            $key = 'opening_deviation|' . ($game->eco_code ?? '?') . '|opening';
            $found[$key] = $found[$key] ?? [
                'kind' => 'opening_deviation',
                'eco' => $game->eco_code,
                'phase' => 'opening',
                'sample_move' => null,
                'sample_best' => null,
                'cp_loss' => null,
            ];
        }

        // Time-pressure collapse heuristic — multiple late blunders in time-class blitz/bullet.
        if (in_array($game->time_class, ['blitz', 'bullet'], true)) {
            $lateBlunders = $moves->filter(fn (MoveAnalysis $m) =>
                $m->color === $userColor &&
                $m->move_number >= 30 &&
                in_array($m->classification, ['blunder', 'mistake'], true)
            )->count();
            if ($lateBlunders >= 2) {
                $key = 'time_pressure_collapse|' . ($game->eco_code ?? '?') . '|middlegame';
                $found[$key] = [
                    'kind' => 'time_pressure_collapse',
                    'eco' => $game->eco_code,
                    'phase' => 'middlegame',
                    'sample_move' => null,
                    'sample_best' => null,
                    'cp_loss' => null,
                ];
            }
        }

        foreach ($found as $entry) {
            $this->upsertPattern($game, $entry);
        }

        return $found;
    }

    private function classifyMistake(MoveAnalysis $m, Game $game): array
    {
        $kinds = [];
        $cpLoss = (int) ($m->cp_loss ?? 0);
        $cls = $m->classification;

        if ($cls === 'blunder' || $cpLoss >= 300) {
            $kinds[] = 'hung_piece';   // close enough as a coarse bucket; refine in v2
            if ($this->phaseFor((int) $m->move_number) === 'endgame') {
                $kinds[] = 'endgame_technique_failure';
            }
        } elseif ($cls === 'mistake' || $cpLoss >= 150) {
            $kinds[] = 'missed_tactic';
        } elseif ($cls === 'inaccuracy' && $cpLoss >= 70) {
            $kinds[] = 'calculation_error';
        }

        return array_unique($kinds);
    }

    private function phaseFor(int $moveNumber): string
    {
        if ($moveNumber <= 12) return 'opening';
        if ($moveNumber <= 32) return 'middlegame';
        return 'endgame';
    }

    private function upsertPattern(Game $game, array $entry): void
    {
        $existing = UserFailurePattern::where([
            'user_id' => $game->user_id,
            'pattern_kind' => $entry['kind'],
            'opening_eco' => $entry['eco'],
            'phase' => $entry['phase'],
        ])->first();

        if ($existing) {
            $ids = $existing->last_game_ids_json ?? [];
            if (! in_array($game->id, $ids, true)) {
                array_unshift($ids, $game->id);
                $ids = array_slice($ids, 0, 10);
                $existing->update([
                    'occurrence_count' => $existing->occurrence_count + 1,
                    'last_seen_at' => $game->played_at ?? Carbon::now(),
                    'last_game_ids_json' => $ids,
                ]);
            }
            return;
        }

        UserFailurePattern::create([
            'user_id' => $game->user_id,
            'pattern_kind' => $entry['kind'],
            'opening_eco' => $entry['eco'],
            'phase' => $entry['phase'],
            'occurrence_count' => 1,
            'first_seen_at' => $game->played_at ?? Carbon::now(),
            'last_seen_at' => $game->played_at ?? Carbon::now(),
            'last_game_ids_json' => [$game->id],
            'sample_position_json' => [
                'move_san' => $entry['sample_move'],
                'best_san' => $entry['sample_best'],
                'cp_loss' => $entry['cp_loss'],
            ],
        ]);
    }
}
