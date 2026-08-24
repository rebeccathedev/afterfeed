<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->text('bio')->nullable();
            $table->text('website')->nullable();
            $table->string('location')->nullable();
            $table->text('avatar_path')->nullable();
            $table->text('header_path')->nullable();
            $table->string('timezone')->nullable();
            $table->boolean('verified')->default(false);
        });
        Schema::table('posts', fn (Blueprint $table) => $table->timestamp('deleted_at')->nullable()->index());

        Schema::create('profile_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('archive_id')->constrained()->cascadeOnDelete()->unique();
            $table->text('bio')->nullable();
            $table->text('website')->nullable();
            $table->string('location')->nullable();
            $table->text('avatar_path')->nullable();
            $table->text('header_path')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('account_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('handle');
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();
            $table->unique(['social_account_id', 'handle']);
        });
        Schema::create('liked_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->text('body')->nullable();
            $table->text('url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['social_account_id', 'external_id']);
        });
        Schema::create('social_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('archive_id')->constrained()->cascadeOnDelete();
            $table->string('direction');
            $table->string('external_account_id');
            $table->text('url')->nullable();
            $table->timestamps();
            $table->unique(['archive_id', 'direction', 'external_account_id'], 'social_connections_archive_direction_unique');
        });
        Schema::create('social_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('archive_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->string('name')->nullable();
            $table->timestamps();
        });
        Schema::create('saved_searches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('archive_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->nullable();
            $table->text('query');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_searches');
        Schema::dropIfExists('social_lists');
        Schema::dropIfExists('social_connections');
        Schema::dropIfExists('liked_posts');
        Schema::dropIfExists('account_aliases');
        Schema::dropIfExists('profile_snapshots');
        Schema::table('posts', fn (Blueprint $table) => $table->dropColumn('deleted_at'));
        Schema::table('social_accounts', fn (Blueprint $table) => $table->dropColumn(['bio', 'website', 'location', 'avatar_path', 'header_path', 'timezone', 'verified']));
    }
};
