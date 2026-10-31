<?php

declare(strict_types=1);

namespace Atannex\Bridge\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $user_id
 */
class Document extends Model
{
    protected $guarded = [];
}
