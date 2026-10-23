<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('pennant-experiments.connection');
    }

    public function up(): void
    {
        // Run state per experiment (the variants themselves live in code).
        Schema::create(config('pennant-experiments.tables.experiments', 'pennant_experiments'), function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('status', 16)->default('running');
            $table->string('winner', 100)->nullable();
            $table->string('ended_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        // One row per scope that actually saw the experiment: the denominator of every rate.
        Schema::create(config('pennant-experiments.tables.exposures', 'pennant_experiment_exposures'), function (Blueprint $table) {
            $table->id();
            $table->string('experiment', 100);
            $table->string('variant', 100);
            $table->string('scope');
            $table->timestamp('exposed_at');
            $table->unique(['experiment', 'scope']);
            $table->index(['experiment', 'variant']);
        });

        // One row per exposed scope and goal: unique converters, not events.
        Schema::create(config('pennant-experiments.tables.conversions', 'pennant_experiment_conversions'), function (Blueprint $table) {
            $table->id();
            $table->string('experiment', 100);
            $table->string('goal', 100);
            $table->string('variant', 100);
            $table->string('scope');
            $table->timestamp('converted_at');
            $table->unique(['experiment', 'goal', 'scope']);
            $table->index(['experiment', 'goal', 'variant']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('pennant-experiments.tables.conversions', 'pennant_experiment_conversions'));
        Schema::dropIfExists(config('pennant-experiments.tables.exposures', 'pennant_experiment_exposures'));
        Schema::dropIfExists(config('pennant-experiments.tables.experiments', 'pennant_experiments'));
    }
};
