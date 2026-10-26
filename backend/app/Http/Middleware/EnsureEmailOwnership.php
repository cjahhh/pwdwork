<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailOwnership
{
    public function handle(Request $request, Closure $next): Response
    {
        // Always enforce persisted enrollment, including after disabling new enrollment.
        if ($request->user()?->requiresEmailVerification()) {
            return response()->json([
                'message' => 'Verify your email address before continuing.',
                'code' => 'email_verification_required',
            ], 403);
        }

        return $next($request);
    }
}
