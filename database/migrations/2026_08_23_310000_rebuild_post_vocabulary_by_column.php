<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('DROP TABLE IF EXISTS posts_fts_vocab');
            DB::statement("CREATE VIRTUAL TABLE posts_fts_vocab USING fts5vocab(posts_fts, 'col')");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('DROP TABLE IF EXISTS posts_fts_vocab');
            DB::statement("CREATE VIRTUAL TABLE posts_fts_vocab USING fts5vocab(posts_fts, 'row')");
        }
    }
};
