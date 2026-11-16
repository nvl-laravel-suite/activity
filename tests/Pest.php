<?php

declare(strict_types=1);

use Nvl\Activity\Tests\CommittedTestCase;
use Nvl\Activity\Tests\SpatieIntegrationTestCase;
use Nvl\Activity\Tests\TenancyTestCase;
use Nvl\Activity\Tests\TestCase;
use Nvl\Activity\Tests\TimelineRecipeTestCase;
use Nvl\Ownership\Tests\Fixtures\ReusesAdoptedSchema;

$committed = [__DIR__.'/Feature/ActivityModelCaptureTest.php', __DIR__.'/Feature/ActivitySafetyTest.php', __DIR__.'/Feature/ActivitySchemaAdoptionTest.php'];
$spatie = [__DIR__.'/Feature/SpatieActivityCaptureIntegrationTest.php'];
$recipes = [__DIR__.'/Feature/ActivityTimelineRecipeTest.php'];
uses(CommittedTestCase::class)->in(...$committed);
uses(CommittedTestCase::class)->in('Concurrency');
uses(SpatieIntegrationTestCase::class)->in(...$spatie);
uses(TimelineRecipeTestCase::class)->in(...$recipes);
uses(TestCase::class)->in(...array_diff(glob(__DIR__.'/Feature/*Test.php') ?: [], $committed, $spatie, $recipes));

if (trait_exists(ReusesAdoptedSchema::class)) {
    uses(TenancyTestCase::class)->in('Tenancy');
}
