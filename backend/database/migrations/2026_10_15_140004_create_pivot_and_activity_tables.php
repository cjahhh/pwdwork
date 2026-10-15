<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applicant_accommodation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_profile_id')->constrained('applicant_profiles')->cascadeOnDelete();
            $table->foreignId('accommodation_id')->constrained('accommodations')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['applicant_profile_id', 'accommodation_id'], 'applicant_accommodation_unique');
        });

        Schema::create('job_listing_accommodation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_listing_id')->constrained('job_listings')->cascadeOnDelete();
            $table->foreignId('accommodation_id')->constrained('accommodations')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['job_listing_id', 'accommodation_id'], 'job_listing_accommodation_unique');
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_listing_id')->constrained('job_listings')->cascadeOnDelete();
            $table->text('cover_letter')->nullable();
            $table->string('status', 32)->default('submitted'); // submitted | reviewing | interview | hired | rejected
            $table->timestamps();

            $table->unique(['user_id', 'job_listing_id']);
        });

        Schema::create('saved_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_listing_id')->constrained('job_listings')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'job_listing_id']);
        });

        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
        Schema::dropIfExists('saved_jobs');
        Schema::dropIfExists('applications');
        Schema::dropIfExists('job_listing_accommodation');
        Schema::dropIfExists('applicant_accommodation');
    }
};
