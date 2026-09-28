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
        Schema::create('ingestion_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('pages_fetched')->default(0);
            $table->unsignedInteger('listings_new')->default(0);
            $table->unsignedInteger('listings_updated')->default(0);
            $table->json('errors')->nullable();
            $table->string('status')->default('running');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingestion_runs');
    }
};
