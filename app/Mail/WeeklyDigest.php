<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class WeeklyDigest extends Mailable
{
    use Queueable, SerializesModels;

    public array $stats;
    public string $unsubscribeUrl;

    public function __construct(public User $user)
    {
        $this->stats = $this->gatherStats();
        $this->unsubscribeUrl = url('/api/digest/unsubscribe?token=' . $this->unsubscribeToken());
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Weekly Chess Progress — Chesiq',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.weekly_digest',
            with: [
                'user' => $this->user,
                'stats' => $this->stats,
                'unsubscribeUrl' => $this->unsubscribeUrl,
            ],
        );
    }

    private function gatherStats(): array
    {
        $userId = $this->user->id;
        $oneWeekAgo = now()->subWeek();
        $twoWeeksAgo = now()->subWeeks(2);

        // Puzzle rating change
        $currentRating = DB::table('user_puzzle_ratings')
            ->where('user_id', $userId)
            ->value('rating') ?? 1500;

        // Average accuracy this week vs last week
        $thisWeekGames = $this->user->games()
            ->where('played_at', '>=', $oneWeekAgo)
            ->whereNotNull('analyzed_at')
            ->get();

        $lastWeekGames = $this->user->games()
            ->whereBetween('played_at', [$twoWeeksAgo, $oneWeekAgo])
            ->whereNotNull('analyzed_at')
            ->get();

        $avgAccNow  = $thisWeekGames->avg(fn ($g) => $g->user_color === 'white' ? $g->white_accuracy : $g->black_accuracy) ?? 0;
        $avgAccPrev = $lastWeekGames->avg(fn ($g) => $g->user_color === 'white' ? $g->white_accuracy : $g->black_accuracy) ?? 0;

        // Weakest tactical theme
        $rows = DB::table('user_puzzle_attempts as a')
            ->join('puzzles as p', 'a.puzzle_id', '=', 'p.id')
            ->where('a.user_id', $userId)
            ->where('a.created_at', '>=', now()->subDays(90))
            ->whereNotNull('p.themes')
            ->select('p.themes', 'a.solved')
            ->get();

        $themeStats = [];
        foreach ($rows as $row) {
            $themes = is_string($row->themes)
                ? (json_decode($row->themes, true) ?? explode(' ', $row->themes))
                : [];
            foreach ($themes as $theme) {
                $theme = trim($theme);
                if (! $theme) continue;
                if (! isset($themeStats[$theme])) $themeStats[$theme] = ['s' => 0, 't' => 0];
                $themeStats[$theme]['t']++;
                if ($row->solved) $themeStats[$theme]['s']++;
            }
        }

        $weakestTheme = null;
        $worstRate = 101;
        foreach ($themeStats as $theme => $s) {
            if ($s['t'] < 3) continue;
            $rate = $s['s'] / $s['t'] * 100;
            if ($rate < $worstRate) { $worstRate = $rate; $weakestTheme = $theme; }
        }

        return [
            'puzzle_rating' => round($currentRating),
            'avg_acc_this_week' => round($avgAccNow, 1),
            'avg_acc_delta' => round($avgAccNow - $avgAccPrev, 1),
            'weakest_theme' => $weakestTheme,
            'games_this_week' => $thisWeekGames->count(),
            'streak' => $this->user->current_streak ?? 0,
        ];
    }

    private function unsubscribeToken(): string
    {
        return hash_hmac('sha256', $this->user->email, config('app.key'));
    }
}
