<?php

namespace App\Http\Controllers;

use App\Jobs\SendPasswordResetLink;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordController extends Controller
{
    public function forgot(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        try {
            SendPasswordResetLink::dispatch($data['email'])
                ->onConnection(config('queue.password_reset_connection'))
                ->onQueue(config('queue.password_reset_queue'));
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Password recovery is temporarily unavailable. Please try again later.'], 503);
        }

        return response()->json(['message' => 'Request accepted. If an eligible account exists, we will attempt to email a reset link. Check your inbox and spam folder.'], 202);
    }

    public function reset(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email'], 'token' => ['required', 'string'], 'password' => ['required', 'string', 'min:8', 'confirmed']]);
        $status = Password::reset($data, function ($user, $password) {
            DB::transaction(function () use ($user, $password) {
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                $user->tokens()->delete();
                if (config('session.driver') === 'database') {
                    DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
                }
            });
            event(new PasswordReset($user));
        });
        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => 'This reset link is invalid or expired. Request a new link.'], 422);
        }

        return response()->json(['message' => 'Password reset. Sign in with your new password.']);
    }
}
