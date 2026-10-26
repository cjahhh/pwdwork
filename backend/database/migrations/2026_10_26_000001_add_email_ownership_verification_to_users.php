<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Existing rows keep their values and receive no ownership claim or requirement.
            $table->timestamp('email_ownership_verified_at')->nullable();
            $table->timestamp('email_verification_required_at')->nullable();
            $table->unsignedBigInteger('email_verification_version')->default(0);
        });
    }

    public function down(): void
    {
        // Operational rollback must retain ownership evidence and enrolled-account guards.
        throw new RuntimeException('Retain email verification fields. Roll back to a compatible application release instead.');
    }
};
