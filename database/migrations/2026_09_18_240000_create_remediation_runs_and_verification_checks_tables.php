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
        // 1. Remediation Runs
        if (! Schema::hasTable('remediation_runs')) {
            Schema::create('remediation_runs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
                $table->foreignUuid('patch_id')->nullable()->constrained('patch_artifacts')->nullOnDelete();
                $table->foreignUuid('pull_request_id')->nullable()->constrained('pull_requests')->nullOnDelete();
                $table->string('ci_run_id')->nullable()->index();
                $table->string('deployment_id')->nullable()->index();
                $table->string('status', 32)->default('PR_CREATED')->index();
                $table->string('health_status', 32)->default('PENDING');
                $table->string('security_status', 32)->default('PENDING');
                $table->string('environment', 64)->default('staging');
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['incident_id', 'patch_id']);
            });
        }

        // 2. Verification Checks
        if (! Schema::hasTable('verification_checks')) {
            Schema::create('verification_checks', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('remediation_run_id')->constrained('remediation_runs')->cascadeOnDelete();
                $table->string('check_type', 64);
                $table->string('status', 32)->default('PENDING')->index();
                $table->string('target_endpoint', 512)->nullable();
                $table->integer('exit_code')->nullable();
                $table->integer('response_code')->nullable();
                $table->integer('duration_ms')->default(0);
                $table->json('evidence')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['remediation_run_id', 'check_type']);
            });
        }

        // 3. Add remediated_at to incidents
        if (Schema::hasTable('incidents')) {
            Schema::table('incidents', function (Blueprint $table) {
                if (! Schema::hasColumn('incidents', 'remediated_at')) {
                    $table->timestamp('remediated_at')->nullable()->after('resolved_at');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn('remediated_at');
        });

        Schema::dropIfExists('verification_checks');
        Schema::dropIfExists('remediation_runs');
    }
};
