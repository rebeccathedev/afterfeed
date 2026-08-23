<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookmarked_posts', fn (Blueprint $table) => $table->string('kind')->default('saved'));
        Schema::table('social_lists', fn (Blueprint $table) => $table->json('metadata')->nullable());
        Schema::create('direct_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->string('thread_id')->nullable();
            $table->string('direction')->nullable();
            $table->string('sender')->nullable();
            $table->string('recipient')->nullable();
            $table->text('subject')->nullable();
            $table->text('body')->nullable();
            $table->text('url')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['social_account_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direct_messages');
        Schema::table('social_lists', fn (Blueprint $table) => $table->dropColumn('metadata'));
        Schema::table('bookmarked_posts', fn (Blueprint $table) => $table->dropColumn('kind'));
    }
};
