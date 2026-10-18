<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\JobListingController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'app' => 'PWDWork',
    ]);
});

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/jobs', [JobListingController::class, 'index']);
Route::get('/jobs/{jobListing}', [JobListingController::class, 'show']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/employer/jobs', [JobListingController::class, 'employerListings'])->middleware('role:employer');
    Route::post('/employer/jobs', [JobListingController::class, 'store'])->middleware('role:employer');
    Route::patch('/employer/jobs/{jobListing}', [JobListingController::class, 'update'])->middleware('role:employer');
    Route::get('/employer/applications', [JobListingController::class, 'employerApplications'])->middleware('role:employer');
    Route::patch('/employer/applications/{application}', [JobListingController::class, 'updateApplication'])->middleware('role:employer');
    Route::post('/jobs/{jobListing}/applications', [JobListingController::class, 'apply'])->middleware('role:applicant');
    Route::get('/applicant/applications', [JobListingController::class, 'applicantApplications'])->middleware('role:applicant');
});
