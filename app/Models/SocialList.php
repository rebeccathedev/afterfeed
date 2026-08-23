<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialList extends Model
{
    protected $fillable = ['archive_id', 'url', 'name', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
