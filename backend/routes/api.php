<?php

use App\Http\Controllers\AccommodationRequestController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\JobListingController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecommendationController;
use App\Http\Controllers\ReportController;
use App\Http\Middleware\EnsureAccommodationRequestsReady;
use App\Http\Requests\RequestReviewRequest;
use App\Models\JobListing;
use App\Services\ModerationService;
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
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::prefix('accommodation-requests')->middleware(['role:applicant,employer', EnsureAccommodationRequestsReady::class])->group(function () {
        Route::get('/catalog', [AccommodationRequestController::class, 'catalog']);
        Route::get('/', [AccommodationRequestController::class, 'index']);
        Route::post('/', [AccommodationRequestController::class, 'store'])->middleware(['role:applicant', 'throttle:30,1']);
        Route::get('/{id}', [AccommodationRequestController::class, 'show'])->whereNumber('id');
        Route::post('/{id}/actions', [AccommodationRequestController::class, 'action'])->whereNumber('id')->middleware('throttle:60,1');
        Route::get('/{id}/messages', [AccommodationRequestController::class, 'messages'])->whereNumber('id');
        Route::get('/{id}/events', [AccommodationRequestController::class, 'events'])->whereNumber('id');
    });
    Route::patch('/notifications/{userNotification}/read', [NotificationController::class, 'read']);
    Route::get('/reports', [ReportController::class, 'index'])->middleware('role:applicant,employer');
    Route::post('/reports', [ReportController::class, 'store'])->middleware(['role:applicant,employer', 'throttle:reports']);
    Route::get('/employer/verification-history', [AdminController::class, 'employerHistory'])->middleware('role:employer');
    Route::post('/employer/verification-request', function (RequestReviewRequest $request, ModerationService $service) {
        $service->requestEmployerReview($request->user(), $request->integer('expected_version'));

        return response()->json(['message' => 'Verification review requested.']);
    })->middleware(['role:employer', 'throttle:5,1']);
    Route::get('/employer/jobs/{jobListing}/history', [AdminController::class, 'jobHistory'])->middleware('role:employer');
    Route::post('/employer/jobs/{jobListing}/submit', function (RequestReviewRequest $request, JobListing $jobListing, ModerationService $service) {
        $service->submitJob($request->user(), $jobListing, $request->integer('expected_version'));

        return response()->json(['message' => 'Listing submitted for moderation.']);
    })->middleware(['role:employer', 'throttle:10,1']);
    Route::prefix('admin')->middleware('role:admin')->group(function () {
        Route::get('/overview', [AdminController::class, 'overview']);
        Route::get('/employers', [AdminController::class, 'employers']);
        Route::get('/employers/{employer}', [AdminController::class, 'employer']);
        Route::patch('/employers/{employer}/verification', [AdminController::class, 'verifyEmployer']);
        Route::get('/jobs', [AdminController::class, 'jobs']);
        Route::get('/jobs/{jobListing}', [AdminController::class, 'job']);
        Route::patch('/jobs/{jobListing}/moderation', [AdminController::class, 'moderateJob']);
        Route::get('/users', [AdminController::class, 'users']);
        Route::get('/users/{user}', [AdminController::class, 'user']);
        Route::patch('/users/{user}/status', [AdminController::class, 'updateUser']);
        Route::get('/reports', [AdminController::class, 'reports']);
        Route::get('/reports/{userReport}', [AdminController::class, 'report']);
        Route::patch('/reports/{userReport}', [AdminController::class, 'updateReport']);
        Route::get('/audit-logs', [AdminController::class, 'audit']);
    });
    Route::get('/profile', [ProfileController::class, 'show'])->middleware('role:applicant,employer');
    Route::put('/profile', [ProfileController::class, 'update'])->middleware('role:applicant,employer');
    Route::get('/applicant/saved-jobs', [JobListingController::class, 'saved'])->middleware('role:applicant');
    Route::get('/applicant/recommendations', [RecommendationController::class, 'recommendations'])->middleware('role:applicant');
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
