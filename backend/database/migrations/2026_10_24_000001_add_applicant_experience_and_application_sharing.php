<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicant_profiles', function (Blueprint $table) {
            $table->text('experience')->nullable();
        });
        Schema::table('applications', function (Blueprint $table) {
            // Consent is per application. Existing applications remain private.
            $table->boolean('share_accommodations')->default(false);
        });
        // Status is already a string: no conversion of existing hired records.
    }

    public function down(): void
    {
        Schema::table('applications', fn (Blueprint $table) => $table->dropColumn('share_accommodations'));
        Schema::table('applicant_profiles', fn (Blueprint $table) => $table->dropColumn('experience'));
    }
};
