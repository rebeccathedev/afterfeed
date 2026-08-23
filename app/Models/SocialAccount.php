<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialAccount extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = ['user_id', 'platform', 'external_id', 'handle', 'display_name', 'bio', 'website', 'location', 'avatar_path', 'header_path', 'timezone', 'verified', 'metadata'];

    protected function casts(): array
    {
        return ['verified' => 'boolean', 'metadata' => 'array'];
    }

    public function archives(): HasMany
    {
        return $this->hasMany(Archive::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function likedPosts(): HasMany
    {
        return $this->hasMany(LikedPost::class);
    }
}
