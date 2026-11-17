<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * When we last asked Pixelforge about a generation, so polling rotates
     * through every unfinished generation instead of re-checking the same ones.
     */
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->timestamp('polled_at')->nullable()->after('completed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn('polled_at');
        });
    }
};
