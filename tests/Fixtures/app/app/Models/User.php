<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $table = 'users';

    /** @var array<string, string> */
    protected $casts = ['password' => 'hashed'];
}
