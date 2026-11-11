<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OAuthIdentity extends Model
{
    protected $table = 'v2_oauth_identity';
    protected $guarded = ['id'];
    protected $dateFormat = 'U';
}
