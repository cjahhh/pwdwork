<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\Response;

class ApiSafety
{
    public static function headers(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        return $response;
    }
}
