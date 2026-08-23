<?php

namespace App\Models;

use App\Models\Concerns\ScopedThrough;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    use ScopedThrough;

    protected static function userOwnerRelation(): string
    {
        return 'post';
    }

    protected $fillable = ['post_id', 'type', 'path', 'alt_text', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
