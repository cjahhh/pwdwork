<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Named job_listings so it does not collide with Laravel's queue `jobs` table.
        Schema::create('job_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employer_id')->constrained('employers')->cascadeOnDelete();
            $table->string('title');
            $table->string('location');
            $table->string('employment_type', 32); // Full Time | Part Time | Contract
            $table->string('work_mode', 32); // Remote | Onsite | Hybrid
            $table->text('description');
            $table->string('status', 20)->default('open'); // draft | open | closed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_listings');
    }
};
