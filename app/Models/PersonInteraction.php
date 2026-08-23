<?php

namespace App\Models;

use App\Models\Concerns\ScopedThrough;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonInteraction extends Model
{
    use ScopedThrough;

    protected static function userOwnerRelation(): string
    {
        return 'person';
    }

    protected $fillable = ['person_id', 'social_account_id', 'post_id', 'kind', 'source_type', 'source_id', 'occurred_at', 'excerpt', 'source_url'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
