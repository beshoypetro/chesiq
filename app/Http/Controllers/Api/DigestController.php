<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\WeeklyDigest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DigestController extends Controller
{
    /**
     * GET /api/admin/digest/preview/{userId} — preview the digest for a user.
     */
    public function preview(Request $request, int $userId): JsonResponse
    {
        $user = User::findOrFail($userId);
        $mailable = new WeeklyDigest($user);

        return response()->json([
            'user' => $user->email,
            'stats' => $mailable->stats,
        ]);
    }

    /**
     * GET /api/digest/unsubscribe — unsubscribe a user from weekly digests.
     * Uses an indexed unsubscribe_token column for O(1) lookup instead of a full table scan.
     */
    public function unsubscribe(Request $request): \Illuminate\Http\Response
    {
        $token = $request->query('token');

        if (! $token) {
            return response('Invalid unsubscribe link.', 400)->header('Content-Type', 'text/plain');
        }

        $user = User::where('unsubscribe_token', $token)->first();

        if (! $user) {
            return response('Invalid unsubscribe link.', 400)->header('Content-Type', 'text/plain');
        }

        $user->update(['digest_unsubscribed_at' => now()]);

        return response('You have been unsubscribed from Chesiq weekly emails.', 200)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * Generate (or retrieve) the unsubscribe token for a user.
     * Call this when building digest emails so the token is always populated.
     */
    public static function unsubscribeTokenFor(User $user): string
    {
        if (! $user->unsubscribe_token) {
            $token = hash('sha256', $user->id . $user->email . config('app.key'));
            $user->update(['unsubscribe_token' => $token]);
        }

        return $user->unsubscribe_token;
    }
}
