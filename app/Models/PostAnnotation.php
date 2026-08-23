<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostAnnotation extends Model
{
    protected $fillable = ['post_id', 'note', 'tags', 'favorite', 'hidden', 'place_name', 'latitude', 'longitude'];

    protected function casts(): array
    {
        return ['tags' => 'array', 'favorite' => 'boolean', 'hidden' => 'boolean', 'latitude' => 'float', 'longitude' => 'float'];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
