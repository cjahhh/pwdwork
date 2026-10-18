<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applicant_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('phone')->nullable();
            $table->string('location')->nullable();
            $table->string('preferred_work_mode', 32)->nullable(); // Remote | Onsite | Hybrid
            $table->text('summary')->nullable();
            $table->string('resume_path')->nullable();
            $table->string('pwd_id_number')->nullable();
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applicant_profiles');
    }
};
