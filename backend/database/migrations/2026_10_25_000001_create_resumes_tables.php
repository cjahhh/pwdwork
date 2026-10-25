<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resumes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->string('template', 20)->default('classic');
            $table->json('content');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });
        Schema::create('resume_application_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('resume_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 120);
            $table->string('template', 20);
            $table->unsignedInteger('version');
            $table->json('content');
            $table->timestamp('shared_at');
            $table->timestamp('revoked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resume_application_shares');
        Schema::dropIfExists('resumes');
    }
};
