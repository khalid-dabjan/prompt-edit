<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Our own copy of the image, because Pixelforge's URL expires 24 hours after completion.
     */
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('image_url_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
