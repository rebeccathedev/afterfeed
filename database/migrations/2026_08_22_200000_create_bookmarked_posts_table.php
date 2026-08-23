<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookmarked_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->text('url');
            $table->timestamps();
            $table->unique(['social_account_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookmarked_posts');
    }
};
