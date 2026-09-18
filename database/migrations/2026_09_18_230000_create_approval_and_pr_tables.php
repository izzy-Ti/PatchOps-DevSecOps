<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Approvals
        if (! Schema::hasTable('approvals')) {
            Schema::create('approvals', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
                $table->foreignUuid('patch_id')->constrained('patch_artifacts')->cascadeOnDelete();
                $table->foreignUuid('quality_gate_run_id')->nullable()->constrained('quality_gate_runs')->cascadeOnDelete();
                $table->string('approved_by', 64);
                $table->string('status', 32);
                $table->string('decision', 32);
                $table->text('comment')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();

                $table->index(['incident_id', 'patch_id']);
                $table->index('status');
            });
        }

        // 2. Pull Requests
        if (! Schema::hasTable('pull_requests')) {
            Schema::create('pull_requests', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
                $table->foreignUuid('patch_id')->constrained('patch_artifacts')->cascadeOnDelete();
                $table->string('repository', 255);
                $table->string('branch', 255);
                $table->string('base_branch', 255)->default('main');
                $table->string('commit_sha', 40)->default('PENDING');
                $table->integer('pr_number')->nullable();
                $table->string('pr_url', 512)->nullable();
                $table->string('status', 32)->default('CREATING');
                $table->timestamps();

                $table->index('incident_id');
                $table->index(['repository', 'pr_number']);
                $table->index('status');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pull_requests');
        Schema::dropIfExists('approvals');
    }
};
