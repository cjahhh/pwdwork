<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employers', function (Blueprint $table) {
            $table->text('verification_reason')->nullable();
            $table->timestamp('verification_reviewed_at')->nullable();
            $table->timestamp('verification_requested_at')->nullable();
            $table->unsignedInteger('review_version')->default(1);
            $table->index(['verification_status', 'id']);
        });
        Schema::table('job_listings', function (Blueprint $table) {
            // Existing jobs require a review; no old row is deleted or auto-approved.
            $table->string('moderation_status', 20)->default('pending');
            $table->text('moderation_reason')->nullable();
            $table->timestamp('moderation_reviewed_at')->nullable();
            $table->unsignedInteger('review_version')->default(1);
            $table->index(['moderation_status', 'status', 'id'], 'jobs_public_moderation_index');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('admin_version')->default(1);
            $table->index(['role', 'status', 'id']);
        });
        Schema::table('user_notifications', function (Blueprint $table) {
            $table->string('action_url')->nullable();
            $table->index(['user_id', 'read_at', 'id']);
        });
        Schema::create('user_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')->constrained('users')->restrictOnDelete();
            $table->string('target_type', 20);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('category', 40);
            $table->text('description');
            $table->string('status', 20)->default('pending');
            $table->text('internal_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedInteger('review_version')->default(1);
            // Nullable after resolution: prevents concurrent duplicate open complaints.
            $table->string('open_fingerprint', 64)->nullable()->unique();
            $table->timestamps();
            $table->index(['status', 'id']);
            $table->index(['reporter_id', 'id']);
            $table->index(['target_type', 'target_id']);
        });
        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 20);
            $table->string('action', 80)->index();
            $table->string('target_type', 20);
            $table->unsignedBigInteger('target_id');
            $table->string('before_status', 32)->nullable();
            $table->string('after_status', 32)->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['target_type', 'target_id', 'id']);
        });
    }

    public function down(): void
    {
        // Rollback is intentionally not a data-preservation upgrade strategy.
        Schema::dropIfExists('admin_audit_logs');
        Schema::dropIfExists('user_reports');
        Schema::table('user_notifications', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'read_at', 'id']);
            $table->dropColumn('action_url');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role', 'status', 'id']);
            $table->dropColumn('admin_version');
        });
        Schema::table('job_listings', function (Blueprint $table) {
            $table->dropIndex('jobs_public_moderation_index');
            $table->dropColumn(['moderation_status', 'moderation_reason', 'moderation_reviewed_at', 'review_version']);
        });
        Schema::table('employers', function (Blueprint $table) {
            $table->dropIndex(['verification_status', 'id']);
            $table->dropColumn(['verification_reason', 'verification_reviewed_at', 'verification_requested_at', 'review_version']);
        });
    }
};
