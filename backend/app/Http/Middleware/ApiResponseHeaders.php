<?php

namespace App\Http\Middleware;

use App\Support\ApiSafety;
use Closure;
use Illuminate\Http\Request;

class ApiResponseHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if ($request->is('api/*', 'sanctum/csrf-cookie')) {
            ApiSafety::headers($response);
        }

        return $response;
    }
}
