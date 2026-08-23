<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_collections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('color')->default('#375d4a');
            $table->timestamps();
        });
        Schema::create('collection_post', function (Blueprint $table) {
            $table->foreignId('post_collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['post_collection_id', 'post_id']);
        });
        Schema::create('post_annotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('note')->nullable();
            $table->json('tags')->nullable();
            $table->boolean('favorite')->default(false);
            $table->boolean('hidden')->default(false);
            $table->timestamps();
        });

        match (DB::connection()->getDriverName()) {
            'sqlite' => $this->createSqliteSearch(),
            'mysql', 'mariadb' => DB::statement('ALTER TABLE posts ADD FULLTEXT INDEX posts_body_fulltext (body)'),
            'pgsql' => DB::statement("CREATE INDEX posts_body_fulltext ON posts USING gin (to_tsvector('simple', coalesce(body, '')))") ,
            default => null,
        };
    }

    public function down(): void
    {
        match (DB::connection()->getDriverName()) {
            'sqlite' => $this->dropSqliteSearch(),
            'mysql', 'mariadb' => DB::statement('ALTER TABLE posts DROP INDEX posts_body_fulltext'),
            'pgsql' => DB::statement('DROP INDEX IF EXISTS posts_body_fulltext'),
            default => null,
        };
        Schema::dropIfExists('post_annotations');
        Schema::dropIfExists('collection_post');
        Schema::dropIfExists('post_collections');
    }

    private function createSqliteSearch(): void
    {
        DB::statement("CREATE VIRTUAL TABLE posts_fts USING fts5(body, metadata, content='posts', content_rowid='id')");
        DB::statement("INSERT INTO posts_fts(rowid, body, metadata) SELECT id, coalesce(body, ''), coalesce(metadata, '') FROM posts");
        DB::unprepared("CREATE TRIGGER posts_fts_insert AFTER INSERT ON posts BEGIN INSERT INTO posts_fts(rowid, body, metadata) VALUES (new.id, coalesce(new.body, ''), coalesce(new.metadata, '')); END");
        DB::unprepared("CREATE TRIGGER posts_fts_delete AFTER DELETE ON posts BEGIN INSERT INTO posts_fts(posts_fts, rowid, body, metadata) VALUES ('delete', old.id, coalesce(old.body, ''), coalesce(old.metadata, '')); END");
        DB::unprepared("CREATE TRIGGER posts_fts_update AFTER UPDATE OF body, metadata ON posts BEGIN INSERT INTO posts_fts(posts_fts, rowid, body, metadata) VALUES ('delete', old.id, coalesce(old.body, ''), coalesce(old.metadata, '')); INSERT INTO posts_fts(rowid, body, metadata) VALUES (new.id, coalesce(new.body, ''), coalesce(new.metadata, '')); END");
    }

    private function dropSqliteSearch(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS posts_fts_update');
        DB::unprepared('DROP TRIGGER IF EXISTS posts_fts_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS posts_fts_insert');
        DB::statement('DROP TABLE IF EXISTS posts_fts');
    }
};
