<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ImprovementPlan;
use App\Models\Module;
use App\Models\Track;
use App\Models\User;
use App\Models\UserAssessment;
use App\Models\UserFailurePattern;
use App\Models\UserPlan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Builds and maintains the durable Improvement Plan — the adaptive bridge from
 * "we tested your level" to "here is how you get better".
 *
 * The single public entry point is sync(User): ImprovementPlan. It recomputes
 * the whole object from current DB state (mastery, milestone path, next action,
 * readiness, adaptation log) and records *what changed*. It is cheap (reads
 * only, no LLM required) and idempotent — safe to call from activity hooks.
 *
 * Spec: IMPROVEMENT_PLAN_SPEC.md §2–§8.
 */
class ImprovementPlanService
{
    /** Five mastery dimensions (spec §4). */
    public const DIMENSIONS = ['tactics', 'calculation', 'strategy', 'endgame', 'openings'];

    /** Khan 4-tier mastery + a 0 "not_started" floor (spec §4). */
    public const TIERS = [0 => 'not_started', 1 => 'attempted', 2 => 'familiar', 3 => 'proficient', 4 => 'mastered'];

    /**
     * Named milestones, aligned 1:1 with academy tracks (spec §3). Elo bands are
     * the defaults; if a matching `tracks` row exists, its elo_min/elo_max win.
     */
    public const MILESTONES = [
        ['slug' => 'foundations', 'name' => 'Novice',             'elo_min' => 0,    'elo_max' => 999],
        ['slug' => 'improver',    'name' => 'Improver',           'elo_min' => 1000, 'elo_max' => 1499],
        ['slug' => 'club',        'name' => 'Club Player',        'elo_min' => 1500, 'elo_max' => 1899],
        ['slug' => 'tournament',  'name' => 'Tournament Player',  'elo_min' => 1900, 'elo_max' => 3000],
    ];

    public function __construct(
        private readonly StudyPlanService $studyPlan,
        private readonly PlanGeneratorService $planGenerator,
    ) {}

    /**
     * Recompute (and lazily create) the user's improvement plan from current
     * DB state. Idempotent. Returns the persisted model.
     *
     * @param  bool  $force  Recompute the *_json even when the signals hash is unchanged.
     */
    public function sync(User $user, bool $force = false): ImprovementPlan
    {
        $plan = ImprovementPlan::firstOrNew(['user_id' => $user->id]);
        $isNew = ! $plan->exists;

        $elo = (int) ($user->placement_elo ?? 1200);
        $signals = $this->studyPlan->signals($user);
        $counts = $this->activityCounts($user);
        $hash = $this->signalsHash($user, $elo, $signals, $counts);

        // Cheap-call guard (spec §7): nothing material changed → only touch
        // last_synced_at and reactivation log, never rewrite the heavy *_json.
        if (! $force && ! $isNew && $plan->signals_hash === $hash) {
            $this->maybeReengage($plan);
            $plan->last_synced_at = now();
            $plan->save();

            return $plan;
        }

        $priorMastery = is_array($plan->skill_mastery_json) ? $plan->skill_mastery_json : [];
        $priorMilestoneSlug = $plan->current_milestone_slug;

        $mastery = $this->computeMastery($user, $elo, $signals, $priorMastery);
        $milestones = $this->computeMilestones($user, $elo, $mastery, $counts);
        $current = $this->currentMilestone($milestones, $elo);
        $nextAction = $this->computeNextAction($user, $elo, $mastery, $current);
        $readiness = $this->computeReadiness($mastery, $current, $elo);

        // Trainer framing
        $trainerId = $user->selected_trainer_id;
        $facts = [
            'elo' => $elo,
            'readiness' => $readiness,
            'milestone' => $current,
            'weakest_skill' => $this->weakestDimensionKey($mastery),
            'mastery' => $mastery,
        ];
        $trainerIntro = $this->trainerIntro($user, $facts);

        // Adaptation log — diff prior vs new and append events (spec §7).
        $log = is_array($plan->adaptation_log_json) ? $plan->adaptation_log_json : [];
        $newEvents = $this->diffEvents($user, $priorMastery, $mastery, $priorMilestoneSlug, $current, $readiness, $plan->readiness_score, $isNew);
        if (! empty($newEvents)) {
            $log = array_slice(array_merge($log, $newEvents), -10);
        }

        // This week's tasks — embed the latest non-expired UserPlan, or generate
        // one. PlanGeneratorService has a heuristic fallback so this never blocks
        // on an LLM (spec §3).
        $this->ensureWeeklyPlan($user);

        $now = now();
        $plan->fill([
            'user_id' => $user->id,
            'current_milestone_slug' => $current['slug'],
            'milestones_json' => $milestones,
            'skill_mastery_json' => $mastery,
            'next_action_json' => $nextAction,
            'readiness_score' => $readiness,
            'trainer_id' => $trainerId,
            'trainer_intro' => $trainerIntro,
            'adaptation_log_json' => $log,
            'signals_hash' => $hash,
            'last_synced_at' => $now,
        ]);

        if ($isNew) {
            $plan->placement_elo_at_creation = $user->placement_elo;
            $plan->generated_at = $now;
        }

        // Re-assessment may move the band — refresh the creation snapshot so
        // milestone math reflects the latest placement (spec §7 band_up).
        if (! $isNew && $priorMilestoneSlug !== $current['slug']) {
            $plan->placement_elo_at_creation = $user->placement_elo;
        }

        $plan->save();

        return $plan;
    }

    // ---------------------------------------------------------------------
    // Skill mastery (spec §4)
    // ---------------------------------------------------------------------

    /**
     * Deterministic 5-dimension Khan mastery from assessment weakness profile,
     * puzzle rating, game accuracy, endgame ratings, repertoire deviation and
     * failure patterns.
     *
     * @param  array<string, mixed>  $signals
     * @param  array<string, mixed>  $prior  last sync's mastery (for trend)
     * @return array<string, array<string, mixed>>
     */
    private function computeMastery(User $user, int $elo, array $signals, array $prior): array
    {
        $assessment = $this->latestAssessment($user);
        $weakness = $assessment && is_array($assessment->weakness_profile_json)
            ? $assessment->weakness_profile_json
            : [];

        $patterns = $this->failurePatternCounts($user);

        // Normalize puzzle rating against placement Elo: equal → 0.5, +400 → ~1.0.
        $puzzleNorm = $this->clamp01(0.5 + (($signals['puzzle_rating'] - $elo) / 800));

        // Game accuracy → 0..1 (55% → 0, 95% → 1).
        $accNorm = $signals['avg_accuracy'] > 0
            ? $this->clamp01(($signals['avg_accuracy'] - 55) / 40)
            : null;

        $mastery = [];

        // tactics — assessment accuracy blended with puzzle rating, minus missed-tactic penalty.
        $tacticsScore = $this->blend([
            [$this->themeAccuracy($weakness, 'tactics'), 0.5],
            [$puzzleNorm, 0.5],
        ]);
        $tacticsScore = $this->penalize($tacticsScore, $patterns['missed_tactic'] ?? 0);
        $mastery['tactics'] = $this->dimension('tactics', $tacticsScore, $prior, 'puzzle_rating+assessment');

        // calculation — assessment accuracy blended with game accuracy, minus calc-error penalty.
        $calcScore = $this->blend([
            [$this->themeAccuracy($weakness, 'calculation'), 0.6],
            [$accNorm, 0.4],
        ]);
        $calcScore = $this->penalize($calcScore, $patterns['calculation_error'] ?? 0);
        $mastery['calculation'] = $this->dimension('calculation', $calcScore, $prior, 'assessment+games');

        // strategy — assessment accuracy blended with game accuracy.
        $stratScore = $this->blend([
            [$this->themeAccuracy($weakness, 'strategy'), 0.65],
            [$accNorm, 0.35],
        ]);
        $mastery['strategy'] = $this->dimension('strategy', $stratScore, $prior, 'assessment+games');

        // endgame — assessment accuracy + endgame category ratings, minus technique-failure penalty.
        $egScore = $this->blend([
            [$this->themeAccuracy($weakness, 'endgame'), 0.5],
            [$this->endgameRatingNorm($user, $elo), 0.5],
        ]);
        $egScore = $this->penalize($egScore, $patterns['endgame_technique_failure'] ?? 0);
        $mastery['endgame'] = $this->dimension('endgame', $egScore, $prior, 'endgame_ratings+failure_patterns');

        // openings — repertoire deviation depth, minus opening-deviation penalty.
        $openScore = $this->openingScore($signals['deviation_avg']);
        $openScore = $this->penalize($openScore, $patterns['opening_deviation'] ?? 0);
        $mastery['openings'] = $this->dimension('openings', $openScore, $prior, 'repertoire_deviation+failure_patterns');

        return $mastery;
    }

    /**
     * Build one dimension entry: tier label, level 0..4, score, trend, source.
     *
     * @param  array<string, mixed>  $prior
     * @return array<string, mixed>
     */
    private function dimension(string $key, ?float $score, array $prior, string $source): array
    {
        $level = $this->tierLevel($score);
        $entry = [
            'tier' => self::TIERS[$level],
            'level' => $level,
            'score' => $score === null ? null : round($score, 2),
            'trend' => $this->trend($score, $prior[$key]['score'] ?? null),
            'source' => $source,
        ];

        return $entry;
    }

    /** score < 0.25 attempted, < 0.5 familiar, < 0.75 proficient, >= 0.75 mastered; null → not_started (spec §4). */
    private function tierLevel(?float $score): int
    {
        if ($score === null) {
            return 0;
        }
        if ($score < 0.25) {
            return 1;
        }
        if ($score < 0.5) {
            return 2;
        }
        if ($score < 0.75) {
            return 3;
        }

        return 4;
    }

    private function trend(?float $new, ?float $old): string
    {
        if ($new === null || $old === null) {
            return 'flat';
        }
        $delta = $new - $old;
        if ($delta > 0.03) {
            return 'up';
        }
        if ($delta < -0.03) {
            return 'down';
        }

        return 'flat';
    }

    // ---------------------------------------------------------------------
    // Milestones (spec §3)
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, array<string, mixed>>  $mastery
     * @param  array<string, int>  $counts
     * @return array<int, array<string, mixed>>
     */
    private function computeMilestones(User $user, int $elo, array $mastery, array $counts): array
    {
        $bands = $this->bands();
        $userBandSlug = $this->bandSlugForElo($elo, $bands);
        $userBandIndex = $this->indexOfSlug($bands, $userBandSlug);

        // Per-band skill emphasis + activity targets (concrete, measurable; spec §3).
        $emphasis = [
            'foundations' => ['skills' => [['key' => 'tactics', 'target' => 2], ['key' => 'endgame', 'target' => 1]], 'activities' => 6],
            'improver'    => ['skills' => [['key' => 'tactics', 'target' => 3], ['key' => 'endgame', 'target' => 2]], 'activities' => 8],
            'club'        => ['skills' => [['key' => 'calculation', 'target' => 3], ['key' => 'strategy', 'target' => 3]], 'activities' => 10],
            'tournament'  => ['skills' => [['key' => 'strategy', 'target' => 4], ['key' => 'openings', 'target' => 3]], 'activities' => 12],
        ];

        $activitiesDone = $counts['activities'] ?? 0;
        $out = [];

        foreach ($bands as $i => $band) {
            $status = $i < $userBandIndex ? 'done' : ($i === $userBandIndex ? 'current' : 'locked');

            $criteria = [];

            // Rating target = band ceiling (+1 for the open-ended top band).
            $ratingTarget = $band['elo_max'] >= 3000 ? 1900 : $band['elo_max'] + 1;
            $criteria[] = [
                'key' => 'rating',
                'label' => "Reach {$ratingTarget} rating",
                'target' => $ratingTarget,
                'current' => $elo,
                'pct' => $this->pct($elo, $ratingTarget, $band['elo_min']),
            ];

            // 1–2 skill-mastery targets from the band emphasis.
            foreach ($emphasis[$band['slug']]['skills'] as $skill) {
                $level = $mastery[$skill['key']]['level'] ?? 0;
                $tierName = ucfirst(self::TIERS[$skill['target']]);
                $label = ucfirst($skill['key']).": reach {$tierName}";
                $criteria[] = [
                    'key' => $skill['key'],
                    'label' => $label,
                    'target' => $skill['target'],
                    'current' => $level,
                    'pct' => $this->pct($level, $skill['target'], 0),
                ];
            }

            // Academy-activity count.
            $actTarget = $emphasis[$band['slug']]['activities'];
            $criteria[] = [
                'key' => 'activities',
                'label' => "Complete {$actTarget} academy activities",
                'target' => $actTarget,
                'current' => $activitiesDone,
                'pct' => $this->pct($activitiesDone, $actTarget, 0),
            ];

            // Equal-weight mean of criteria pct (documented weighting, spec §3).
            $progress = (int) round(array_sum(array_column($criteria, 'pct')) / max(1, count($criteria)));
            if ($status === 'done') {
                $progress = 100;
            }

            $out[] = [
                'slug' => $band['slug'],
                'name' => $band['name'],
                'elo_min' => $band['elo_min'],
                'elo_max' => $band['elo_max'],
                'status' => $status,
                'criteria' => $criteria,
                'progress_pct' => $progress,
                'track_slug' => $band['slug'],
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $milestones
     * @return array<string, mixed>
     */
    private function currentMilestone(array $milestones, int $elo): array
    {
        foreach ($milestones as $m) {
            if ($m['status'] === 'current') {
                return $m;
            }
        }

        return $milestones[0];
    }

    // ---------------------------------------------------------------------
    // Next action (spec §5)
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, array<string, mixed>>  $mastery
     * @param  array<string, mixed>  $milestone
     * @return array<string, mixed>
     */
    private function computeNextAction(User $user, int $elo, array $mastery, array $milestone): array
    {
        // Skills the current milestone needs (from its non-rating/activity criteria).
        $needed = [];
        foreach ($milestone['criteria'] as $c) {
            if (in_array($c['key'], self::DIMENSIONS, true)) {
                $needed[] = $c['key'];
            }
        }
        if (empty($needed)) {
            $needed = self::DIMENSIONS;
        }

        // Weakest needed dimension by score (null score sorts weakest).
        $weakest = null;
        $weakestScore = 2.0;
        foreach ($needed as $dim) {
            $score = $mastery[$dim]['score'] ?? null;
            $eff = $score === null ? -1.0 : $score;
            if ($eff < $weakestScore) {
                $weakestScore = $eff;
                $weakest = $dim;
            }
        }
        $weakest = $weakest ?? 'tactics';

        // Prefer the next academy activity in the current band, biased toward the
        // weakest skill when an open module trains it directly.
        $academy = $this->nextAcademyActivity($user, $milestone['track_slug'], $weakest);
        if ($academy) {
            $rationale = $academy['targets_weak_skill']
                ? ucfirst($weakest)." is your biggest gap — this {$milestone['name']} activity targets it directly."
                : "Continue your {$milestone['name']} track — this is your next module activity.";

            return [
                'kind' => 'academy_activity',
                'label' => $academy['title'],
                'rationale' => $rationale,
                'route' => '/academy/module/'.$academy['module_id'],
                'est_minutes' => 15,
                'skill' => $weakest,
                'trainer_line' => $this->trainerActionLine($user, $weakest, $milestone, $academy['title']),
            ];
        }

        // Otherwise map the weakest skill to a one-click activity.
        [$kind, $label, $route, $est] = $this->actionForSkill($user, $weakest);

        return [
            'kind' => $kind,
            'label' => $label,
            'rationale' => ucfirst($weakest)." is your biggest gap for reaching {$milestone['name']}.",
            'route' => $route,
            'est_minutes' => $est,
            'skill' => $weakest,
            'trainer_line' => $this->trainerActionLine($user, $weakest, $milestone, $label),
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: int}
     */
    private function actionForSkill(User $user, string $skill): array
    {
        return match ($skill) {
            'tactics', 'calculation' => (function () use ($user) {
                $theme = $this->studyPlan->weakestTheme($user) ?: 'fork';
                $pretty = str_replace(['_', '-'], ' ', $theme);

                return ['puzzle_set', "Solve 10 {$pretty} puzzles", '/puzzles?theme='.rawurlencode($theme), 10];
            })(),
            'endgame' => ['endgame_set', 'Train 5 endgame positions', '/endgame', 12],
            'openings' => ['repertoire', 'Drill your opening repertoire', '/learn', 12],
            'strategy' => ['lesson', 'Study a strategy lesson at your level', '/academy/club', 15],
            default => ['puzzle_set', 'Solve 10 tactics puzzles', '/puzzles', 10],
        };
    }

    /**
     * Skill dimension → the academy activity types that train it. Lets the next
     * action bias toward the user's weakest area when several modules are open.
     *
     * @var array<string, array<int, string>>
     */
    private const SKILL_ACTIVITY_TYPES = [
        'tactics' => ['puzzle_set'],
        'calculation' => ['puzzle_set'],
        'endgame' => ['endgame_set'],
        'openings' => ['lesson_markdown', 'guided_study'],
        'strategy' => ['lesson_markdown', 'guided_study', 'quiz'],
    ];

    /**
     * The next academy activity to recommend in a track.
     *
     * Walks published modules in true curriculum order — course `display_order`,
     * then module `display_order` (the old code ignored `display_order` and used
     * raw insertion-id order, so a later module could surface before an earlier
     * one). For each not-yet-finished module it resolves the next incomplete
     * activity, forming the "frontier" of startable work. From that frontier it
     * prefers the earliest activity whose type trains the user's weakest skill;
     * with no such match it falls back to the earliest frontier activity (the
     * plain in-order next step).
     *
     * @return array{module_id: int, activity_id: int, title: string, targets_weak_skill: bool}|null
     */
    private function nextAcademyActivity(User $user, string $trackSlug, ?string $weakestSkill = null): ?array
    {
        $track = Track::where('slug', $trackSlug)->first();
        if (! $track) {
            return null;
        }

        // Curriculum-ordered module ids: course order → module order. Raw id is
        // only the final, deterministic tie-break.
        $moduleIds = Module::query()
            ->join('courses', 'courses.id', '=', 'modules.course_id')
            ->where('courses.track_id', $track->id)
            ->where('modules.published', true)
            ->orderBy('courses.display_order')
            ->orderBy('modules.display_order')
            ->orderBy('modules.id')
            ->pluck('modules.id');
        if ($moduleIds->isEmpty()) {
            return null;
        }

        $progress = DB::table('module_progress')
            ->where('user_id', $user->id)
            ->whereIn('module_id', $moduleIds)
            ->pluck('activities_completed', 'module_id');

        $preferredTypes = $weakestSkill ? (self::SKILL_ACTIVITY_TYPES[$weakestSkill] ?? []) : [];

        $firstFrontier = null; // earliest startable activity, irrespective of skill
        foreach ($moduleIds as $mid) {
            $done = (int) ($progress[$mid] ?? 0);
            $activity = Activity::where('module_id', $mid)
                ->where('published', true)
                ->orderBy('display_order')
                ->skip($done)
                ->first();
            if (! $activity) {
                continue;
            }

            $frontier = [
                'module_id' => (int) $mid,
                'activity_id' => $activity->id,
                'title' => $activity->title,
                'targets_weak_skill' => in_array($activity->type, $preferredTypes, true),
            ];

            // The first module whose next activity already trains the weakest
            // skill wins — relevant *and* as early in the path as possible.
            if ($frontier['targets_weak_skill']) {
                return $frontier;
            }
            $firstFrontier ??= $frontier;
        }

        return $firstFrontier;
    }

    // ---------------------------------------------------------------------
    // Readiness (spec §6)
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, array<string, mixed>>  $mastery
     * @param  array<string, mixed>  $milestone
     */
    private function computeReadiness(array $mastery, array $milestone, int $elo): int
    {
        $levels = array_map(fn ($d) => $d['level'] ?? 0, $mastery);
        $masteryBreadth = count($levels) ? (array_sum($levels) / count($levels)) / 4 : 0;

        $milestoneProgress = ($milestone['progress_pct'] ?? 0) / 100;

        $bandMin = $milestone['elo_min'];
        $bandMax = $milestone['elo_max'] >= 3000 ? 2400 : $milestone['elo_max'];
        $ratingPos = $bandMax > $bandMin
            ? $this->clamp01(($elo - $bandMin) / ($bandMax - $bandMin))
            : 0.0;

        $readiness = 0.45 * $masteryBreadth + 0.40 * $milestoneProgress + 0.15 * $ratingPos;

        return (int) round($readiness * 100);
    }

    // ---------------------------------------------------------------------
    // Peer-percentile benchmarking (spec §10 — "how you compare")
    // ---------------------------------------------------------------------

    /** Minimum peer cohort before a percentile is statistically worth showing. */
    public const MIN_PEER_COHORT = 4;

    /** Half-width of the rating band that defines "peers" (± this many Elo). */
    private const PEER_BAND_HALF_WIDTH = 150;

    /**
     * Benchmark this user's skill mastery + readiness against other placed users
     * in the same rating band, as percentile ranks (0–100 = share of peers at or
     * below the user).
     *
     * Deterministic and $0 — a single read over `improvement_plans` (every row
     * already carries `skill_mastery_json`, `readiness_score` and
     * `placement_elo_at_creation`); percentiles are computed in PHP, no extra
     * table and no per-peer game joins.
     *
     * Honest empty state: with fewer than {@see MIN_PEER_COHORT} peers the cohort
     * is too small to be meaningful, so `available` is false and the UI says so
     * rather than showing a misleading number.
     *
     * @return array{available: bool, cohort_size: int, band: array{min: int|null, max: int|null}, readiness_percentile: int|null, skills: array<int, array{key: string, percentile: int}>}
     */
    public function peerBenchmark(User $user, ImprovementPlan $plan): array
    {
        $ref = $plan->placement_elo_at_creation ?? $user->placement_elo;
        $bandMin = $ref !== null ? (int) $ref - self::PEER_BAND_HALF_WIDTH : null;
        $bandMax = $ref !== null ? (int) $ref + self::PEER_BAND_HALF_WIDTH : null;

        $empty = [
            'available' => false,
            'cohort_size' => 0,
            'band' => ['min' => $bandMin, 'max' => $bandMax],
            'readiness_percentile' => null,
            'skills' => [],
        ];

        if ($ref === null) {
            return $empty;
        }

        $peers = ImprovementPlan::query()
            ->where('user_id', '!=', $user->id)
            ->whereNotNull('placement_elo_at_creation')
            ->whereBetween('placement_elo_at_creation', [$bandMin, $bandMax])
            ->get(['skill_mastery_json', 'readiness_score']);

        $cohort = $peers->count();
        if ($cohort < self::MIN_PEER_COHORT) {
            return array_merge($empty, ['cohort_size' => $cohort]);
        }

        // Readiness percentile across the cohort.
        $peerReadiness = $peers
            ->map(fn ($p) => $p->readiness_score)
            ->filter(fn ($v) => $v !== null)
            ->map(fn ($v) => (int) $v)
            ->all();
        $readinessPct = $this->percentileRank($peerReadiness, (int) $plan->readiness_score);

        // Per-dimension mastery-level percentile. `level` (0–4) is always present,
        // so it's a clean ordinal to rank on (unlike the nullable `score`).
        $mastery = is_array($plan->skill_mastery_json) ? $plan->skill_mastery_json : [];
        $skills = [];
        foreach (self::DIMENSIONS as $key) {
            $userLevel = $mastery[$key]['level'] ?? null;
            if ($userLevel === null) {
                continue;
            }

            $peerLevels = [];
            foreach ($peers as $p) {
                $pm = is_array($p->skill_mastery_json) ? ($p->skill_mastery_json[$key]['level'] ?? null) : null;
                if ($pm !== null) {
                    $peerLevels[] = (int) $pm;
                }
            }
            if (count($peerLevels) < self::MIN_PEER_COHORT) {
                continue;
            }

            $skills[] = [
                'key' => $key,
                'percentile' => $this->percentileRank($peerLevels, (int) $userLevel),
            ];
        }

        return [
            'available' => true,
            'cohort_size' => $cohort,
            'band' => ['min' => $bandMin, 'max' => $bandMax],
            'readiness_percentile' => $readinessPct,
            'skills' => $skills,
        ];
    }

    /**
     * Percentile rank (0–100) of $value within $peers — the share of peers at or
     * below it. Empty cohort → null.
     *
     * @param  array<int, int>  $peers
     */
    private function percentileRank(array $peers, int $value): ?int
    {
        $n = count($peers);
        if ($n === 0) {
            return null;
        }

        $atOrBelow = 0;
        foreach ($peers as $p) {
            if ($p <= $value) {
                $atOrBelow++;
            }
        }

        return (int) round(100 * $atOrBelow / $n);
    }

    // ---------------------------------------------------------------------
    // Adaptation log (spec §7)
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $priorMastery
     * @param  array<string, array<string, mixed>>  $newMastery
     * @param  array<string, mixed>  $newMilestone
     * @return array<int, array<string, mixed>>
     */
    private function diffEvents(
        User $user,
        array $priorMastery,
        array $newMastery,
        ?string $priorMilestoneSlug,
        array $newMilestone,
        int $newReadiness,
        ?int $priorReadiness,
        bool $isNew,
    ): array {
        $events = [];
        $at = now()->toIso8601String();

        if ($isNew) {
            $events[] = [
                'at' => $at,
                'type' => 'milestone_progress',
                'message' => "Your plan is set — you're a {$newMilestone['name']} working toward the next milestone.",
                'trainer_framed' => true,
            ];

            return $events;
        }

        // Band change.
        if ($priorMilestoneSlug !== null && $priorMilestoneSlug !== $newMilestone['slug']) {
            $events[] = [
                'at' => $at,
                'type' => 'band_up',
                'message' => "New milestone reached — you're now working at {$newMilestone['name']}.",
                'trainer_framed' => true,
            ];
        }

        // Skill tier increases.
        foreach ($newMastery as $key => $dim) {
            $oldLevel = $priorMastery[$key]['level'] ?? null;
            $newLevel = $dim['level'] ?? 0;
            if ($oldLevel !== null && $newLevel > $oldLevel) {
                $tier = ucfirst($dim['tier']);
                $events[] = [
                    'at' => $at,
                    'type' => 'skill_up',
                    'message' => ucfirst($key)." leveled up to {$tier} — {$newMilestone['name']} progress is now {$newMilestone['progress_pct']}%.",
                    'trainer_framed' => true,
                ];
            }
        }

        // Readiness jump (≥ 5).
        if ($priorReadiness !== null && ($newReadiness - $priorReadiness) >= 5) {
            $delta = $newReadiness - $priorReadiness;
            $events[] = [
                'at' => $at,
                'type' => 'milestone_progress',
                'message' => "Your readiness is {$newReadiness} — up {$delta} since last time.",
                'trainer_framed' => true,
            ];
        }

        return $events;
    }

    /** Append a light re-engagement nudge if the user has been away > 7 days. */
    private function maybeReengage(ImprovementPlan $plan): void
    {
        $last = $plan->last_synced_at;
        if (! $last instanceof Carbon || $last->gt(now()->subDays(7))) {
            return;
        }

        $log = is_array($plan->adaptation_log_json) ? $plan->adaptation_log_json : [];
        $latest = end($log);
        if (is_array($latest) && ($latest['type'] ?? null) === 'reengage') {
            return; // don't spam
        }

        $log[] = [
            'at' => now()->toIso8601String(),
            'type' => 'reengage',
            'message' => "Welcome back — pick up where you left off with one quick session today.",
            'trainer_framed' => true,
        ];
        $plan->adaptation_log_json = array_slice($log, -10);
    }

    // ---------------------------------------------------------------------
    // Trainer framing (spec §8) — deterministic templated copy by default,
    // optional Gemini enhancement behind the same availability check
    // PlanGeneratorService uses, with graceful fallback. Never hard-coded.
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $facts
     */
    private function trainerIntro(User $user, array $facts): string
    {
        $deterministic = $this->deterministicIntro($user, $facts);

        $enhanced = $this->geminiIntro($user, $facts, $deterministic);

        return $enhanced ?? $deterministic;
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function deterministicIntro(User $user, array $facts): string
    {
        $trainerId = $user->selected_trainer_id ?: 'king';
        $cfg = config("trainers.{$trainerId}") ?? config('trainers.king');
        $name = $cfg['name'] ?? 'Your coach';
        $tagline = rtrim((string) ($cfg['tagline'] ?? ''), '.');
        $specialty = is_array($cfg['specialty'] ?? null)
            ? str_replace('_', ' ', implode(', ', $cfg['specialty']))
            : '';

        $milestone = $facts['milestone']['name'] ?? 'your level';
        $readiness = $facts['readiness'] ?? 0;
        $weakest = str_replace('_', ' ', (string) ($facts['weakest_skill'] ?? 'tactics'));

        $lead = $tagline !== '' ? "I'm {$name} — {$tagline}." : "I'm {$name}.";
        $article = in_array(strtolower($milestone[0] ?? 'x'), ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a';

        return "{$lead} You're {$article} {$milestone} with a readiness of {$readiness}. ".
            "We'll lean on my work in {$specialty} and put {$weakest} front and center until it stops holding you back.";
    }

    /**
     * Optional Gemini rewrite in the trainer's persona voice. Mirrors
     * PlanGeneratorService's availability check + graceful fallback (spec §8).
     *
     * @param  array<string, mixed>  $facts
     */
    private function geminiIntro(User $user, array $facts, string $deterministic): ?string
    {
        $apiKey = config('services.gemini.key');
        if (! $apiKey) {
            return null;
        }

        $persona = $user->trainerPersona();
        if (! $persona) {
            return null;
        }

        $system = $persona."\n\n".
            'Rewrite the following improvement-plan intro in your voice. One or two sentences, '.
            'warm and motivating, no markdown, keep all the facts (milestone, readiness, focus skill). '.
            'Output plain text only.';

        $model = config('services.gemini.model', 'gemini-2.0-flash');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        try {
            $response = Http::timeout(20)->post($url, [
                'system_instruction' => ['parts' => [['text' => $system]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $deterministic]]]],
                'generationConfig' => ['maxOutputTokens' => 200, 'temperature' => 0.7, 'thinkingConfig' => ['thinkingBudget' => 0]],
            ]);
        } catch (\Throwable $e) {
            Log::warning('ImprovementPlan Gemini intro error', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $text = trim((string) $response->json('candidates.0.content.parts.0.text', ''));

        return $text !== '' ? $text : null;
    }

    /**
     * @param  array<string, mixed>  $milestone
     */
    private function trainerActionLine(User $user, string $skill, array $milestone, string $label): string
    {
        $trainerId = $user->selected_trainer_id ?: 'king';
        $cfg = config("trainers.{$trainerId}") ?? config('trainers.king');
        $name = $cfg['name'] ?? 'Your coach';
        $skillPretty = str_replace('_', ' ', $skill);
        $milestoneName = $milestone['name'] ?? 'the next level';

        // Persona-flavored fragment seeded from the trainer's default mode.
        $opener = match ($cfg['default_mode'] ?? 'tutor') {
            'debate' => "Let's settle this",
            'socratic' => "Here's a question for you",
            default => "Let's work on this together",
        };

        return "{$opener}: I'm {$name}, and {$skillPretty} is the gap between you and {$milestoneName}. ".
            "Start here — {$label}.";
    }

    // ---------------------------------------------------------------------
    // Weekly task embedding (spec §3) — reuse PlanGeneratorService / UserPlan.
    // ---------------------------------------------------------------------

    private function ensureWeeklyPlan(User $user): ?UserPlan
    {
        $existing = UserPlan::where('user_id', $user->id)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('generated_at')
            ->first();

        if ($existing) {
            return $existing;
        }

        // Heuristic fallback always available — never blocks on the LLM.
        $payload = $this->planGenerator->generate($user);

        return UserPlan::create([
            'user_id' => $user->id,
            'generated_at' => now(),
            'trainer_id' => $user->selected_trainer_id,
            'elo_at_generation' => $user->placement_elo,
            'intents_json' => is_array($user->intents) ? $user->intents : [],
            'plan_json' => $payload,
            'expires_at' => now()->addDays(7),
        ]);
    }

    public function latestWeeklyPlan(User $user): ?UserPlan
    {
        return UserPlan::where('user_id', $user->id)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('generated_at')
            ->first();
    }

    // ---------------------------------------------------------------------
    // Signal helpers
    // ---------------------------------------------------------------------

    private function latestAssessment(User $user): ?UserAssessment
    {
        return UserAssessment::where('user_id', $user->id)
            ->whereNotNull('completed_at')
            ->orderByDesc('completed_at')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $weakness
     */
    private function themeAccuracy(array $weakness, string $theme): ?float
    {
        if (! isset($weakness[$theme]['accuracy'])) {
            return null;
        }

        return $this->clamp01((float) $weakness[$theme]['accuracy']);
    }

    /** @return array<string, int> */
    private function failurePatternCounts(User $user): array
    {
        return UserFailurePattern::where('user_id', $user->id)
            ->pluck('occurrence_count', 'pattern_kind')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    private function endgameRatingNorm(User $user, int $elo): ?float
    {
        $avg = DB::table('user_endgame_ratings')
            ->where('user_id', $user->id)
            ->avg('rating');

        if ($avg === null) {
            return null;
        }

        return $this->clamp01(0.5 + (($avg - $elo) / 800));
    }

    private function openingScore(float $deviationAvg): ?float
    {
        if ($deviationAvg <= 0) {
            return null;
        }

        // Deeper average deviation ply = better prep. 0 ply → 0, 20+ ply → ~1.
        return $this->clamp01($deviationAvg / 20);
    }

    /**
     * @return array<string, int>
     */
    private function activityCounts(User $user): array
    {
        return [
            'games' => (int) $user->games()->whereNotNull('analyzed_at')->count(),
            'attempts' => (int) DB::table('user_puzzle_attempts')->where('user_id', $user->id)->count(),
            'activities' => (int) DB::table('module_progress')->where('user_id', $user->id)->sum('activities_completed'),
            'patterns' => (int) UserFailurePattern::where('user_id', $user->id)->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $signals
     * @param  array<string, int>  $counts
     */
    private function signalsHash(User $user, int $elo, array $signals, array $counts): string
    {
        return sha1(json_encode([
            'elo' => $elo,
            'puzzle_rating' => round($signals['puzzle_rating']),
            'avg_accuracy' => round($signals['avg_accuracy'], 1),
            'deviation_avg' => round($signals['deviation_avg'], 1),
            'weak_phase' => $signals['weak_phase'],
            'weak_theme' => $signals['weak_theme'],
            'games' => $counts['games'],
            'attempts' => $counts['attempts'],
            'activities' => $counts['activities'],
            'patterns' => $counts['patterns'],
            'trainer_id' => $user->selected_trainer_id,
            'intents' => is_array($user->intents) ? $user->intents : [],
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, array<string, mixed>>  $mastery
     */
    private function weakestDimensionKey(array $mastery): string
    {
        $weakest = 'tactics';
        $weakestScore = 2.0;
        foreach ($mastery as $key => $dim) {
            $score = $dim['score'] ?? null;
            $eff = $score === null ? -1.0 : $score;
            if ($eff < $weakestScore) {
                $weakestScore = $eff;
                $weakest = $key;
            }
        }

        return $weakest;
    }

    // ---------------------------------------------------------------------
    // Small math helpers
    // ---------------------------------------------------------------------

    /**
     * Weighted blend of [value, weight] pairs, skipping null values and
     * renormalizing. Returns null if every input is null.
     *
     * @param  array<int, array{0: ?float, 1: float}>  $pairs
     */
    private function blend(array $pairs): ?float
    {
        $sum = 0.0;
        $w = 0.0;
        foreach ($pairs as [$value, $weight]) {
            if ($value === null) {
                continue;
            }
            $sum += $value * $weight;
            $w += $weight;
        }

        return $w > 0 ? $sum / $w : null;
    }

    /** Subtract a small penalty per recurring failure pattern (capped). */
    private function penalize(?float $score, int $count): ?float
    {
        if ($score === null || $count <= 0) {
            return $score;
        }

        return $this->clamp01($score - min(0.2, $count * 0.04));
    }

    private function clamp01(float $v): float
    {
        return max(0.0, min(1.0, $v));
    }

    /** Progress percent of value toward target from a floor, clamped 0..100. */
    private function pct(int|float $value, int|float $target, int|float $floor): int
    {
        if ($target <= $floor) {
            return $value >= $target ? 100 : 0;
        }
        $p = (($value - $floor) / ($target - $floor)) * 100;

        return (int) round(max(0, min(100, $p)));
    }

    // ---------------------------------------------------------------------
    // Band utilities (use tracks rows when present, else MILESTONES defaults).
    // ---------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function bands(): array
    {
        $tracks = Track::orderBy('elo_min')->get()->keyBy('slug');
        $out = [];
        foreach (self::MILESTONES as $m) {
            $t = $tracks->get($m['slug']);
            $out[] = [
                'slug' => $m['slug'],
                'name' => $m['name'],
                'elo_min' => $t ? (int) $t->elo_min : $m['elo_min'],
                'elo_max' => $t ? (int) $t->elo_max : $m['elo_max'],
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $bands
     */
    private function bandSlugForElo(int $elo, array $bands): string
    {
        foreach ($bands as $b) {
            if ($elo >= $b['elo_min'] && $elo <= $b['elo_max']) {
                return $b['slug'];
            }
        }

        // Above the highest band's ceiling → top band; below the lowest → first.
        return $elo > end($bands)['elo_max'] ? end($bands)['slug'] : $bands[0]['slug'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $bands
     */
    private function indexOfSlug(array $bands, string $slug): int
    {
        foreach ($bands as $i => $b) {
            if ($b['slug'] === $slug) {
                return $i;
            }
        }

        return 0;
    }
}
