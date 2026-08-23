<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 40);
            $table->string('identifier');
            $table->string('display_name')->nullable();
            $table->text('profile_url')->nullable();
            $table->timestamps();
            $table->unique(['platform', 'identifier']);
            $table->index('display_name');
        });
        Schema::create('person_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('post_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('source_type', 40);
            $table->string('source_id');
            $table->timestamp('occurred_at')->nullable();
            $table->text('excerpt')->nullable();
            $table->text('source_url')->nullable();
            $table->timestamps();
            $table->unique(['person_id', 'social_account_id', 'kind', 'source_type', 'source_id'], 'person_interaction_source_unique');
            $table->index(['person_id', 'occurred_at']);
            $table->index(['kind', 'person_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_interactions');
        Schema::dropIfExists('people');
    }
};
