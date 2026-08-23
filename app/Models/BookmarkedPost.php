<?php

namespace App\Models;

use App\Models\Concerns\ScopedThrough;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookmarkedPost extends Model
{
    use ScopedThrough;

    protected static function userOwnerRelation(): string
    {
        return 'socialAccount';
    }

    protected $fillable = ['social_account_id', 'external_id', 'url', 'kind'];

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }
}
