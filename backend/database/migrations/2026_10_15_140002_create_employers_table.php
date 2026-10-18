<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('company_name');
            $table->string('company_logo', 8)->nullable();
            $table->string('company_color')->nullable();
            $table->string('website')->nullable();
            $table->string('location')->nullable();
            $table->text('description')->nullable();
            $table->string('verification_status', 20)->default('pending'); // pending | verified | rejected
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employers');
    }
};
