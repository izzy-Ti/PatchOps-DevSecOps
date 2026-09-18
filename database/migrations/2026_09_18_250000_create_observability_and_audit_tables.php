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
        // 1. Traces
        if (! Schema::hasTable('traces')) {
            Schema::create('traces', function (Blueprint $table) {
                $table->string('id', 64)->primary();
                $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
                $table->string('correlation_id', 64)->index();
                $table->string('status', 32)->default('ACTIVE')->index();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['incident_id', 'status']);
            });
        }

        // 2. Tool Calls
        if (! Schema::hasTable('tool_calls')) {
            Schema::create('tool_calls', function (Blueprint $table) {
                $table->string('id', 64)->primary();
                $table->unsignedBigInteger('agent_run_id')->nullable()->index();
                $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
                $table->string('trace_id', 64)->nullable()->index();
                $table->string('tool_name', 128);
                $table->string('server_name', 64)->default('internal');
                $table->string('permission_scope', 64)->nullable();
                $table->string('risk_level', 32)->default('LOW');
                $table->json('arguments')->nullable();
                $table->json('result')->nullable();
                $table->string('status', 32)->default('SUCCESS')->index();
                $table->integer('exit_code')->nullable();
                $table->integer('duration_ms')->default(0);
                $table->text('error')->nullable();
                $table->timestamp('created_at')->useCurrent()->index();

                $table->index(['incident_id', 'tool_name']);
            });
        }

        // 3. Immutable Audit Events
        if (! Schema::hasTable('audit_events')) {
            Schema::create('audit_events', function (Blueprint $table) {
                $table->string('id', 64)->primary();
                $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
                $table->string('actor_type', 32)->index();
                $table->string('actor_id', 64)->index();
                $table->string('action', 64)->index();
                $table->string('resource_type', 64)->nullable()->index();
                $table->string('resource_id', 64)->nullable();
                $table->json('metadata')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('created_at')->useCurrent()->index();

                $table->index(['incident_id', 'created_at']);
            });
        }

        // 4. Enhance agent_runs table
        if (Schema::hasTable('agent_runs')) {
            Schema::table('agent_runs', function (Blueprint $table) {
                if (! Schema::hasColumn('agent_runs', 'trace_id')) {
                    $table->string('trace_id', 64)->nullable()->after('incident_id')->index();
                }
                if (! Schema::hasColumn('agent_runs', 'model')) {
                    $table->string('model', 64)->nullable()->after('status');
                }
                if (! Schema::hasColumn('agent_runs', 'input_tokens')) {
                    $table->integer('input_tokens')->nullable()->after('model');
                }
                if (! Schema::hasColumn('agent_runs', 'output_tokens')) {
                    $table->integer('output_tokens')->nullable()->after('input_tokens');
                }
                if (! Schema::hasColumn('agent_runs', 'duration_ms')) {
                    $table->integer('duration_ms')->default(0)->after('output_tokens');
                }
                if (! Schema::hasColumn('agent_runs', 'iteration')) {
                    $table->unsignedInteger('iteration')->default(1)->after('attempt');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropIndex(['trace_id']);
            $table->dropColumn(['trace_id', 'model', 'input_tokens', 'output_tokens', 'duration_ms', 'iteration']);
        });

        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('tool_calls');
        Schema::dropIfExists('traces');
    }
};
