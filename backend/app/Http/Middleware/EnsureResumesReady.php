<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class EnsureResumesReady
{
    public function handle(Request $request, Closure $next)
    {
        if (! Schema::hasTable('resumes') || ! Schema::hasTable('resume_application_shares')) {
            return response()->json(['message' => 'Resume builder is temporarily unavailable. Please try again later.'], 503)->header('Cache-Control', 'private, no-store');
        }

        return $next($request)->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
