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
     */
    public function unsubscribe(Request $request): \Illuminate\Http\Response
    {
        $token = $request->query('token');
        $users = User::all();

        foreach ($users as $user) {
            $expected = hash_hmac('sha256', $user->email, config('app.key'));
            if (hash_equals($expected, (string) $token)) {
                $user->update(['digest_unsubscribed_at' => now()]);
                return response('You have been unsubscribed from Chesiq weekly emails.', 200)
                    ->header('Content-Type', 'text/plain');
            }
        }

        return response('Invalid unsubscribe link.', 400)->header('Content-Type', 'text/plain');
    }
}
