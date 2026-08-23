<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProfileSnapshot extends Model
{
    protected $fillable = ['archive_id', 'bio', 'website', 'location', 'avatar_path', 'header_path', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
