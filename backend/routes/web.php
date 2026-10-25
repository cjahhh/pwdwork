<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json(['app' => 'PWDWork', 'status' => 'ok']);
});

Route::get('/api/csrf-cookie', fn () => response()->noContent());
