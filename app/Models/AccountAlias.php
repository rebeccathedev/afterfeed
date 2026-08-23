<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountAlias extends Model
{
    protected $fillable = ['social_account_id', 'handle', 'changed_at'];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }
}
