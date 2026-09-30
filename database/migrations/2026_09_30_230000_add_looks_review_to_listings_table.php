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
        Schema::table('listings', function (Blueprint $table) {
            // Filled in by the car-looks-evaluator skill after a photo review — the scrapers
            // can't judge looks for free, so these stay null until someone reviews the car.
            $table->unsignedTinyInteger('looks_score')->nullable();
            $table->text('looks_notes')->nullable();
            $table->timestamp('looks_scored_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['looks_score', 'looks_notes', 'looks_scored_at']);
        });
    }
};
