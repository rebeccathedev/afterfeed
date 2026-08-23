<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_annotations', function (Blueprint $table) {
            $table->string('place_name')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('post_annotations', function (Blueprint $table) {
            $table->dropColumn(['place_name', 'latitude', 'longitude']);
        });
    }
};
