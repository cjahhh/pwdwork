<?php

use App\Http\Middleware\ApiResponseHeaders;
use App\Http\Middleware\EnsureUserRole;
use App\Support\ApiSafety;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(ApiResponseHeaders::class);
        // Only provider-controlled proxy addresses may supply client IP / HTTPS headers.
        $middleware->trustProxies(
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_AWS_ELB,
        );
        $middleware->statefulApi();
        $middleware->throttleApi('api');
        $middleware->alias([
            'role' => EnsureUserRole::class,
        ]);

        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('api/*')) {
                return null;
            }

            return '/login';
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Production logs deliberately exclude exception messages/SQL bindings and request data.
        $exceptions->report(function (Throwable $exception) {
            if (app()->environment('production')) {
                Log::error('Application exception; inspect protected monitoring and reproduce in staging.', [
                    'exception_type' => get_class($exception),
                ]);

                return false;
            }
        });
        // Private request errors must stay safe even when local debug mode is enabled.
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if ($request->is('api/applicant/resumes', 'api/applicant/resumes/*', 'api/applications/*/built-resume', 'api/applications/*/built-resume/*') && $response->getStatusCode() >= 400) {
                $status = $response->getStatusCode();
                $payload = ['message' => match ($status) {
                    401 => 'Unauthenticated.',
                    403, 404 => 'Resume not found or you do not have permission to access it.',
                    409 => 'This resume changed in another window. Reload before saving.',
                    422 => 'Please check the resume information and try again.',
                    429 => 'Too many attempts. Please wait before trying again.',
                    default => 'Resumes are temporarily unavailable. Please try again later.',
                }];
                if ($exception instanceof ValidationException) {
                    $payload['errors'] = $exception->errors();
                }
                $safe = response()->json($payload, $status)->header('Cache-Control', 'private, no-store');
                foreach (['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'] as $header) {
                    if ($response->headers->has($header)) {
                        $safe->headers->set($header, $response->headers->get($header));
                    }
                }

                return ApiSafety::headers($safe);
            }
            if (! $request->is('api/accommodation-requests', 'api/accommodation-requests/*') || $response->getStatusCode() < 400) {
                if ($request->is('api/*') && $response->getStatusCode() >= 400) {
                    $status = $response->getStatusCode();
                    $payload = ['message' => match ($status) {
                        401 => 'Unauthenticated.',
                        403 => 'You do not have permission to perform this action.',
                        404 => 'Resource not found.',
                        419 => 'Your security session expired. Reload the page and try again.',
                        409, 422 => $exception instanceof HttpExceptionInterface ? $exception->getMessage() : 'Please check the information and try again.',
                        429 => 'Too many attempts. Please wait before trying again.',
                        default => $status >= 500 ? 'The service is temporarily unavailable. Please try again later.' : 'The request could not be completed.',
                    }];
                    if ($exception instanceof ValidationException) {
                        $payload['errors'] = $exception->errors();
                    }
                    $safe = response()->json($payload, $status);
                    foreach (['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset', 'Allow'] as $header) {
                        if ($response->headers->has($header)) {
                            $safe->headers->set($header, $response->headers->get($header));
                        }
                    }

                    return ApiSafety::headers($safe);
                }

                return $response;
            }
            $status = $response->getStatusCode();
            $payload = ['message' => match ($status) {
                401 => 'Unauthenticated.',
                403, 404 => 'Accommodation request not found or you do not have permission to view it.',
                409 => 'This request changed or the action is unavailable. Refresh the request and try again.',
                422 => 'Please check the request information and try again.',
                429 => 'Too many attempts. Please wait before trying again.',
                default => 'Accommodation requests are temporarily unavailable. Please try again later.',
            }];
            if ($exception instanceof ValidationException) {
                $payload['errors'] = $exception->errors();
            }
            $safe = response()->json($payload, $status)->header('Cache-Control', 'private, no-store');
            foreach (['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'] as $header) {
                if ($response->headers->has($header)) {
                    $safe->headers->set($header, $response->headers->get($header));
                }
            }

            return ApiSafety::headers($safe);
        });
        // API clients should get JSON 401, not a redirect to a missing "login" named route.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });
    })->create();
