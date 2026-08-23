<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PostCollection extends Model
{
    use BelongsToUser;

    protected $fillable = ['user_id', 'name', 'description', 'color'];

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'collection_post')->withTimestamps();
    }
}
