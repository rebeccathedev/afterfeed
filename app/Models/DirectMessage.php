<?php

namespace App\Models;

use App\Models\Concerns\ScopedThrough;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DirectMessage extends Model
{
    use ScopedThrough;

    protected static function userOwnerRelation(): string
    {
        return 'socialAccount';
    }

    protected $fillable = ['social_account_id', 'external_id', 'thread_id', 'direction', 'sender', 'recipient', 'subject', 'body', 'url', 'sent_at', 'metadata'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'metadata' => 'array'];
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }
}
