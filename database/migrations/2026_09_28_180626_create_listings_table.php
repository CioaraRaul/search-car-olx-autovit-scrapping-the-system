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
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('external_id');
            $table->string('title')->nullable();
            $table->unsignedInteger('price');
            $table->string('currency', 3);
            $table->unsignedSmallInteger('year')->nullable();
            $table->unsignedInteger('mileage_km')->nullable();
            $table->unsignedInteger('engine_capacity_cc')->nullable();
            $table->unsignedSmallInteger('horsepower')->nullable();
            $table->string('body_type')->nullable();
            $table->string('transmission')->nullable();
            $table->string('fuel_type')->nullable();
            $table->string('city')->nullable();
            $table->text('url');
            $table->json('photos')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};
