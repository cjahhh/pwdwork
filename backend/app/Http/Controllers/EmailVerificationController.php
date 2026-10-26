<?php

namespace App\Http\Controllers;

use App\Services\EmailVerification;
use App\Support\EmailVerificationDiagnostics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class EmailVerificationController extends Controller
{
    public function status(Request $request, EmailVerification $verification): JsonResponse
    {
        return response()->json([
            'available' => $verification->available($request->user()),
            'email_verified' => $request->user()->hasVerifiedEmail(),
            'email_verification_required' => $request->user()->requiresEmailVerification(),
        ]);
    }

    public function resend(Request $request, EmailVerification $verification): JsonResponse
    {
        abort_unless($verification->available($request->user()), 404);
        try {
            $retry = $verification->request($request->user());
        } catch (Throwable) {
            EmailVerificationDiagnostics::record('dispatch_failed');

            return response()->json(['message' => 'Verification email is temporarily unavailable. Please try again later.'], 503);
        }
        if ($retry > 0) {
            return response()->json(['message' => 'Please wait before requesting another verification email.'], 429)
                ->header('Retry-After', (string) $retry);
        }

        return response()->json(['message' => 'Request accepted. If verification is needed, we will attempt to send an email. Check your inbox and spam folder.'], 202);
    }

    public function confirm(Request $request, EmailVerification $verification): JsonResponse
    {
        abort_unless($verification->available($request->user()), 404);
        // No validation exception may flash/echo the proof or a submitted URL.
        $proof = $request->json('proof');
        if (! is_string($proof) || strlen($proof) > 2048 || ! $verification->confirm($request->user(), $proof)) {
            return response()->json(['message' => 'This verification link is invalid or expired. Request a new email.'], 422);
        }
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json(['message' => 'Email address verified.']);
    }
}
