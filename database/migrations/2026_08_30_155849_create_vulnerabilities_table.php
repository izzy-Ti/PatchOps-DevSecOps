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
        Schema::create('vulnerabilities', function (Blueprint $table) {
            $table->id();
            $table->string('source')->index();
            $table->string('source_id');
            $table->string('cve_id')->nullable()->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('severity')->index();
            $table->string('package_name')->index();
            $table->string('affected_version')->nullable();
            $table->string('fixed_version')->nullable();
            $table->string('repository')->index();
            $table->text('reference_url')->nullable();
            $table->json('raw_data')->nullable();
            $table->string('status')->default('open')->index();
            $table->timestamp('first_detected_at')->nullable();
            $table->timestamp('last_detected_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'source_id'], 'vulnerabilities_source_source_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vulnerabilities');
    }
};
