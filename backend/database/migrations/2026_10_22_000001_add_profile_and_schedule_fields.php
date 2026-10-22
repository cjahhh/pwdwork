<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicant_profiles', function (Blueprint $table) {
            $table->string('headline')->nullable();
            $table->json('skills')->nullable();
        });
        Schema::table('job_listings', function (Blueprint $table) {
            $table->boolean('flexible_schedule')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('applicant_profiles', fn (Blueprint $table) => $table->dropColumn(['headline', 'skills']));
        Schema::table('job_listings', fn (Blueprint $table) => $table->dropColumn('flexible_schedule'));
    }
};
