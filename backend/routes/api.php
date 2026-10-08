<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| API routes for PWDWork.
| Login, jobs, and role middleware will be added in later phases.
| Sanctum already protects /user (requires a Bearer token).
*/

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'app' => 'PWDWork',
    ]);
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
