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
        // 1. Patch Artifacts
        Schema::create('patch_artifacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->unsignedInteger('iteration')->default(1);
            $table->longText('diff');
            $table->text('fix_summary')->nullable();
            $table->json('files_changed')->nullable();
            $table->json('tests_added')->nullable();
            $table->string('status', 32)->default('candidate');
            $table->timestamps();

            $table->index(['incident_id', 'iteration']);
            $table->index('status');
        });

        // 2. Quality Gate Runs
        Schema::create('quality_gate_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->foreignUuid('patch_id')->constrained('patch_artifacts')->cascadeOnDelete();
            $table->unsignedInteger('iteration')->default(1);
            $table->boolean('passed')->default(false);
            $table->unsignedInteger('total_checks')->default(0);
            $table->unsignedInteger('passed_checks')->default(0);
            $table->unsignedInteger('failed_checks')->default(0);
            $table->decimal('duration_ms', 10, 2)->default(0.00);
            $table->timestamps();

            $table->index(['incident_id', 'patch_id']);
            $table->index('passed');
        });

        // 3. Quality Gate Checks
        Schema::create('quality_gate_checks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('quality_gate_run_id')->constrained('quality_gate_runs')->cascadeOnDelete();
            $table->string('check_type', 64);
            $table->string('status', 32)->default('pending');
            $table->integer('exit_code')->default(0);
            $table->longText('stdout')->nullable();
            $table->longText('stderr')->nullable();
            $table->decimal('duration_ms', 10, 2)->default(0.00);
            $table->boolean('is_critical')->default(true);
            $table->string('failure_classification', 64)->nullable();
            $table->timestamps();

            $table->index(['quality_gate_run_id', 'check_type']);
            $table->index('status');
        });

        // 4. Incident patch iteration tracking
        Schema::table('incidents', function (Blueprint $table) {
            $table->unsignedInteger('patch_iterations')->default(1)->after('status');
            $table->text('escalation_reason')->nullable()->after('root_cause');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn(['patch_iterations', 'escalation_reason']);
        });

        Schema::dropIfExists('quality_gate_checks');
        Schema::dropIfExists('quality_gate_runs');
        Schema::dropIfExists('patch_artifacts');
    }
};
