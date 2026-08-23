<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropUnique(['platform', 'external_id']);
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->unique(['user_id', 'platform', 'external_id']);
        });
        Schema::table('archives', function (Blueprint $table) {
            $table->dropUnique(['fingerprint']);
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->unique(['user_id', 'fingerprint']);
        });
        Schema::table('post_collections', fn (Blueprint $table) => $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete());
        Schema::table('people', function (Blueprint $table) {
            $table->dropUnique(['platform', 'identifier']);
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->unique(['user_id', 'platform', 'identifier']);
        });

        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->json('value')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'key']);
        });
        DB::table('app_settings')->orderBy('key')->get()->each(fn ($setting) => DB::table('user_settings')->insert([
            'key' => $setting->key, 'value' => $setting->value, 'created_at' => $setting->created_at, 'updated_at' => $setting->updated_at,
        ]));
        Schema::drop('app_settings');
        Schema::rename('user_settings', 'app_settings');

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('app_settings');
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });
        Schema::table('people', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'platform', 'identifier']);
            $table->dropConstrainedForeignId('user_id');
            $table->unique(['platform', 'identifier']);
        });
        Schema::table('post_collections', fn (Blueprint $table) => $table->dropConstrainedForeignId('user_id'));
        Schema::table('archives', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'fingerprint']);
            $table->dropConstrainedForeignId('user_id');
            $table->unique('fingerprint');
        });
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'platform', 'external_id']);
            $table->dropConstrainedForeignId('user_id');
            $table->unique(['platform', 'external_id']);
        });
    }
};
