<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * All money columns are unsigned integers in nanodollars (1 USD = 1,000,000,000),
     * the same precision as Pixelforge's `cost_usd`.
     */
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->string('pixelforge_id')->nullable()->unique();
            $table->string('status');

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            $table->unsignedTinyInteger('markup_percent');
            $table->unsignedBigInteger('estimated_min_charge_nanodollars');
            $table->unsignedBigInteger('estimated_max_charge_nanodollars');

            $table->json('rates')->nullable();
            $table->unsignedInteger('input_text_tokens')->nullable();
            $table->unsignedInteger('input_image_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('reasoning_tokens')->nullable();
            $table->unsignedBigInteger('provider_cost_nanodollars')->nullable();
            $table->unsignedBigInteger('charge_nanodollars')->nullable();
            $table->boolean('charge_waived')->default(false);

            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
