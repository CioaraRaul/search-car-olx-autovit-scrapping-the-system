<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_reputations', function (Blueprint $table) {
            $table->id();
            $table->string('make');
            $table->string('model');
            // Groups of words that must ALL appear in an ad title for it to be this model,
            // e.g. [["vw","passat"],["volkswagen","passat"]] — any one group matching is enough.
            $table->json('keywords');
            $table->string('verdict'); // recommended | acceptable | avoid
            $table->string('reason');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['make', 'model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_reputations');
    }
};
