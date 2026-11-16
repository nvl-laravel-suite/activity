<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;

/** Host-owned mail table with a `category` column instead of `message_category`. */
final class TestHostMailLog extends Model
{
    protected $table = 'host_mail_logs';

    protected $guarded = [];

    public $timestamps = false;
}
