<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Stubs;

use Illuminate\Database\Eloquent\Model as Activity;

/** Represents unrelated host-owned activity storage. */
final class HostActivity extends Activity
{
    protected $table = 'host_activity';

    protected $guarded = [];
}
