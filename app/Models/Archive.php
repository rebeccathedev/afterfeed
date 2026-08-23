<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Archive extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = ['user_id', 'social_account_id', 'label', 'fingerprint', 'exported_at', 'imported_at', 'status', 'metadata'];

    protected function casts(): array
    {
        return ['exported_at' => 'datetime', 'imported_at' => 'datetime', 'metadata' => 'array'];
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class);
    }
}
