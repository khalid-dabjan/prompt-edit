<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * An append-only ledger of balance changes (amounts in signed nanodollars).
     * The unique (generation_id, type) index means a generation can only ever
     * have one hold, one release and one charge, whatever retries or duplicate
     * webhooks happen.
     */
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('generation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->bigInteger('amount_nanodollars');
            $table->timestamps();

            $table->unique(['generation_id', 'type']);
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
