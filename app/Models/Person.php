<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Person extends Model
{
    use BelongsToUser;

    protected $fillable = ['user_id', 'platform', 'identifier', 'display_name', 'profile_url'];

    public function interactions(): HasMany
    {
        return $this->hasMany(PersonInteraction::class);
    }
}
