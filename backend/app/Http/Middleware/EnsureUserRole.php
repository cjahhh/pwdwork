<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserRole
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user && $user->status !== 'active') {
            return response()->json(['message' => 'This account is suspended or inactive. Contact support if you need help.', 'code' => 'account_inactive'], 403);
        }

        if (! $user || (! in_array($user->role, $roles, true) || $user->status !== 'active')) {
            return response()->json([
                'message' => 'You are not allowed to access this resource.',
            ], 403);
        }

        return $next($request);
    }
}
