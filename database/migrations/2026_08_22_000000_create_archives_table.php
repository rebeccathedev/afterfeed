<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('platform')->index();
            $table->string('external_id')->nullable();
            $table->string('handle')->nullable();
            $table->string('display_name')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['platform', 'external_id']);
        });

        Schema::create('archives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('fingerprint')->unique();
            $table->timestamp('exported_at')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->string('status')->default('ready');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->string('type')->default('post');
            $table->text('body')->nullable();
            $table->text('url')->nullable();
            $table->timestamp('posted_at')->nullable()->index();
            $table->string('reply_to_external_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['social_account_id', 'external_id']);
        });
        Schema::create('archive_post', function (Blueprint $table) {
            $table->foreignId('archive_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->primary(['archive_id', 'post_id']);
        });
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->text('path');
            $table->text('alt_text')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('archive_post');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('archives');
        Schema::dropIfExists('social_accounts');
    }
};
