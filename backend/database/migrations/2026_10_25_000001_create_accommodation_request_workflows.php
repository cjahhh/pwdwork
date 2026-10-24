<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accommodation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->restrictOnDelete();
            $table->uuid('submission_key');
            $table->string('submission_hash', 64);
            $table->json('categories');
            $table->string('stage', 20);
            $table->text('details')->nullable();
            $table->string('status', 24)->default('submitted');
            $table->text('arrangement')->nullable();
            $table->json('interview_snapshot')->nullable();
            $table->json('arrangement_interview')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('shared_at');
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
            $table->unique(['application_id', 'submission_key'], 'accommodation_submission_unique');
            $table->index(['application_id', 'status', 'id'], 'accommodation_application_status');
            $table->index(['status', 'id']);
        });
        Schema::create('accommodation_request_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accommodation_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->string('sender_role', 20);
            $table->text('body');
            $table->timestamps();
            $table->index(['accommodation_request_id', 'id'], 'accommodation_message_order');
        });
        Schema::create('accommodation_request_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accommodation_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 20);
            $table->uuid('action_key');
            $table->string('action_hash', 64);
            $table->string('action', 24);
            $table->string('before_status', 24)->nullable();
            $table->string('after_status', 24);
            $table->text('explanation')->nullable();
            $table->json('snapshot');
            $table->unsignedInteger('version');
            $table->timestamps();
            $table->unique(['accommodation_request_id', 'action_key'], 'accommodation_action_unique');
            $table->index(['accommodation_request_id', 'id'], 'accommodation_event_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accommodation_request_events');
        Schema::dropIfExists('accommodation_request_messages');
        Schema::dropIfExists('accommodation_requests');
    }
};
