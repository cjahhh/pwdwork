<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccommodationRequestsReady
{
    public function handle(Request $request, Closure $next): Response
    {
        foreach (['accommodation_requests', 'accommodation_request_messages', 'accommodation_request_events'] as $table) {
            if (! Schema::hasTable($table)) {
                return response()->json(['message' => 'Accommodation requests are temporarily unavailable. Please try again later.'], 503)
                    ->header('Cache-Control', 'private, no-store');
            }
        }

        return $next($request);
    }
}
