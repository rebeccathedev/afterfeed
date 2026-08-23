<?php

namespace App\Models;

use App\Models\Concerns\ScopedThrough;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LikedPost extends Model
{
    use ScopedThrough;

    protected static function userOwnerRelation(): string
    {
        return 'socialAccount';
    }

    protected $fillable = ['social_account_id', 'external_id', 'body', 'url', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }
}
