<?php

namespace App\Console\Commands;

use App\Mail\WeeklyDigest;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendWeeklyDigests extends Command
{
    protected $signature = 'digest:send {--user= : Send to a specific user ID (for testing)}';

    protected $description = 'Send weekly progress digest emails to all subscribed users.';

    public function handle(): int
    {
        $userId = $this->option('user');

        $query = User::query()->whereNull('digest_unsubscribed_at');
        if ($userId) {
            $query->where('id', $userId);
        }

        $users = $query->get();
        $sent = 0;

        foreach ($users as $user) {
            try {
                Mail::to($user->email)->queue(new WeeklyDigest($user));
                $sent++;
            } catch (\Throwable $e) {
                $this->error("Failed to queue digest for {$user->email}: {$e->getMessage()}");
            }
        }

        $this->info("Queued weekly digest for {$sent} user(s).");

        return self::SUCCESS;
    }
}
