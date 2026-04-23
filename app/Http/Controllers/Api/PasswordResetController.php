<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        // Dev-mode escape hatch: if mail is going to the log file (MAIL_MAILER=log),
        // surface the reset link directly in the response so local users can actually reset.
        // This ONLY runs when APP_ENV=local — production keeps the privacy-preserving flow.
        if (app()->environment('local') && config('mail.default') === 'log') {
            $user = User::where('email', $request->email)->first();
            if ($user) {
                $token     = Password::createToken($user);
                $frontend  = rtrim(env('FRONTEND_URL', 'http://localhost:8080'), '/');
                $resetUrl  = "{$frontend}/reset-password?token={$token}&email=" . urlencode($user->email);
                return response()->json([
                    'message'       => 'Dev mode: email is logged, use the direct reset URL below.',
                    'dev_reset_url' => $resetUrl,
                ]);
            }
            // Fall through to generic flow if user not found — keeps behavior uniform in tests
        }

        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json(['message' => 'Password reset link sent to your email.']);
        }

        return response()->json([
            'message' => 'Unable to send reset link. Please check the email address.',
        ], 422);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token'                 => 'required',
            'email'                 => 'required|email',
            'password'              => 'required|string|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])
                     ->setRememberToken(Str::random(60));
                $user->save();
                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Password has been reset successfully.']);
        }

        return response()->json(['message' => 'Invalid or expired reset token.'], 422);
    }
}
