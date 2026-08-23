<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

trait ScopedThrough
{
    protected static function bootScopedThrough(): void
    {
        static::addGlobalScope('user', function (Builder $query): void {
            if (Auth::id()) {
                $query->whereHas(static::userOwnerRelation());
            }
        });
    }
}
