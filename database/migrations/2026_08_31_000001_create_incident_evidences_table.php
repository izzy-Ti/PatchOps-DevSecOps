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
        Schema::create('incident_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->string('stage')->default('reproduction');
            $table->boolean('reproduced')->default(false);
            $table->string('command');
            $table->integer('exit_code')->default(0);
            $table->longText('stdout')->nullable();
            $table->longText('stderr')->nullable();
            $table->decimal('duration_ms', 10, 2)->default(0.00);
            $table->json('environment')->nullable();
            $table->json('artifacts')->nullable();
            $table->json('observations')->nullable();
            $table->timestamps();

            $table->index(['incident_id', 'stage']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incident_evidences');
    }
};
