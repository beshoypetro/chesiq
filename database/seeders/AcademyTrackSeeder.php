<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\Course;
use App\Models\Module;
use App\Models\Track;
use Illuminate\Database\Seeder;

/**
 * Seeds the four-track skeleton (Foundations / Improver / Club / Tournament).
 * Each track gets a starter "Welcome" course with one module + lesson activity.
 * Real curriculum content is generated via ContentGenerationService and reviewed
 * in the admin UI.
 */
class AcademyTrackSeeder extends Seeder
{
    public function run(): void
    {
        $tracks = [
            ['slug' => 'foundations', 'name' => 'Foundations', 'elo_min' => 400, 'elo_max' => 1000, 'display_order' => 1,
                'description' => 'Basic tactical patterns, K+P endgames, opening principles, board vision.'],
            ['slug' => 'improver', 'name' => 'Improver', 'elo_min' => 1000, 'elo_max' => 1500, 'display_order' => 2,
                'description' => 'Intermediate tactics, basic pawn structures, R endgames, repertoire intro.'],
            ['slug' => 'club', 'name' => 'Club Player', 'elo_min' => 1500, 'elo_max' => 1900, 'display_order' => 3,
                'description' => 'Advanced tactics, IQP/hanging pawns, master game studies, full repertoire build.'],
            ['slug' => 'tournament', 'name' => 'Tournament', 'elo_min' => 1900, 'elo_max' => 2400, 'display_order' => 4,
                'description' => 'Deep calculation, prophylaxis, complex endgames, time management.'],
        ];

        foreach ($tracks as $t) {
            $track = Track::updateOrCreate(['slug' => $t['slug']], $t + ['published' => true]);

            $course = Course::updateOrCreate(
                ['track_id' => $track->id, 'slug' => 'welcome'],
                ['name' => "Welcome to {$track->name}", 'description' => 'Orientation and starter modules.', 'display_order' => 1],
            );

            $module = Module::updateOrCreate(
                ['course_id' => $course->id, 'slug' => 'getting-started'],
                ['name' => 'Getting started', 'overview' => 'A short orientation lesson.', 'display_order' => 1, 'published' => true],
            );

            Activity::updateOrCreate(
                ['module_id' => $module->id, 'type' => 'lesson_markdown', 'title' => 'Welcome'],
                [
                    'config' => [
                        'markdown' => "# Welcome to {$track->name}\n\nThis track is for players in the {$track->elo_min}–{$track->elo_max} range.\n\n{$track->description}",
                    ],
                    'display_order' => 1,
                    'published' => true,
                ],
            );
        }
    }
}
