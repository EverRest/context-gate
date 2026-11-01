<?php

declare(strict_types=1);

namespace ContextGate\Tests\Fixtures\Quickstart\Models;

use Illuminate\Database\Eloquent\Model;

/** Quickstart fixture (spec 004): the users table. Test data only; secrets are FAKE_FIXTURE_ONLY. */
class User extends Model
{
    protected $table = 'users';
}
