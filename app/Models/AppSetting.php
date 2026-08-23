<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    use BelongsToUser;

    protected $fillable = ['user_id', 'key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
