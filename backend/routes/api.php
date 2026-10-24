<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\JobListingController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'app' => 'PWDWork',
    ]);
});

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:registration');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/forgot-password', [PasswordController::class, 'forgot'])->middleware('throttle:5,1');
Route::post('/reset-password', [PasswordController::class, 'reset'])->middleware('throttle:10,1');
Route::get('/jobs', [JobListingController::class, 'index']);
Route::get('/jobs/{jobListing}', [JobListingController::class, 'show']);

Route::middleware(['auth:sanctum', 'role:applicant,employer,admin'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'show'])->middleware('role:applicant,employer');
    Route::put('/profile', [ProfileController::class, 'update'])->middleware('role:applicant,employer');
    Route::get('/applicant/saved-jobs', [JobListingController::class, 'saved'])->middleware('role:applicant');
    Route::get('/applicant/saved-jobs/{jobListing}', [JobListingController::class, 'savedState'])->middleware('role:applicant');
    Route::put('/applicant/saved-jobs/{jobListing}', [JobListingController::class, 'saveJob'])->middleware('role:applicant');
    Route::delete('/applicant/saved-jobs/{jobListing}', [JobListingController::class, 'unsaveJob'])->middleware('role:applicant');
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/employer/jobs', [JobListingController::class, 'employerListings'])->middleware('role:employer');
    Route::post('/employer/jobs', [JobListingController::class, 'store'])->middleware('role:employer');
    Route::patch('/employer/jobs/{jobListing}', [JobListingController::class, 'update'])->middleware('role:employer');
    Route::get('/employer/applications', [JobListingController::class, 'employerApplications'])->middleware('role:employer');
    Route::get('/employer/applications/{application}/resume', [JobListingController::class, 'resume'])->middleware('role:employer');
    Route::patch('/employer/applications/{application}', [JobListingController::class, 'updateApplication'])->middleware('role:employer');
    Route::post('/jobs/{jobListing}/applications', [JobListingController::class, 'apply'])->middleware('role:applicant');
    Route::get('/applicant/applications', [JobListingController::class, 'applicantApplications'])->middleware('role:applicant');
    Route::patch('/applicant/applications/{application}', [JobListingController::class, 'updateSharing'])->middleware('role:applicant');
});
