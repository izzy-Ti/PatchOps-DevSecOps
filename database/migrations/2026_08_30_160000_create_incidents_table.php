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
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vulnerability_id')->nullable()->constrained('vulnerabilities')->nullOnDelete();
            $table->string('correlation_id')->index();
            $table->string('incident_number')->unique()->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('severity')->default('medium')->index();
            $table->string('priority')->default('medium')->index();
            $table->string('status')->default('received')->index();
            $table->string('repository')->nullable()->index();
            $table->string('environment')->nullable()->default('sandbox');
            $table->longText('root_cause')->nullable();
            $table->string('assigned_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
