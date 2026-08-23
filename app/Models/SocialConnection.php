<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialConnection extends Model
{
    protected $fillable = ['archive_id', 'direction', 'external_account_id', 'url'];
}
