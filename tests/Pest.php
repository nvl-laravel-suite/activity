<?php

declare(strict_types=1);

use Nvl\Activity\Tests\CommittedTestCase;
use Nvl\Activity\Tests\TenancyTestCase;
use Nvl\Activity\Tests\TestCase;

$committed = [__DIR__.'/Feature/ActivityModelCaptureTest.php', __DIR__.'/Feature/ActivitySafetyTest.php'];
uses(CommittedTestCase::class)->in(...$committed);
uses(TestCase::class)->in(...array_diff(glob(__DIR__.'/Feature/*Test.php') ?: [], $committed));

uses(TenancyTestCase::class)->in('Tenancy');
