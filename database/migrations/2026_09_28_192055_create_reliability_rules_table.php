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
        Schema::create('reliability_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->json('keywords');
            $table->unsignedTinyInteger('penalty');
            $table->string('message');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reliability_rules');
    }
};
