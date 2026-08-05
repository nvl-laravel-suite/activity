<?php

declare(strict_types=1);

namespace Nvl\Activity\Console\Commands;

use Illuminate\Console\Command;
use Nvl\Activity\Enums\ActivityDoctorSeverity;
use Nvl\Activity\Services\ActivityDoctor;

/**
 * Reports activity package readiness without changing state.
 */
final class ActivityDoctorCommand extends Command
{
    protected $signature = 'nvl:activity:doctor
        {--strict : Fail for warnings as well as errors}
        {--format=text : Output format: text or json}';

    protected $description = 'Inspect the activity package schema and runtime configuration';

    /**
     * Execute the read-only package readiness inspection.
     */
    public function handle(ActivityDoctor $doctor): int
    {
        $checks = $doctor->inspect();
        $strict = (bool) $this->option('strict');
        $failed = collect($checks)->contains(
            static fn ($check): bool => ! $check->passed
                && ($strict || $check->severity === ActivityDoctorSeverity::Error),
        );

        if ($this->option('format') === 'json') {
            $this->line((string) json_encode([
                'healthy' => ! $failed,
                'checks' => array_map(
                    static fn ($check): array => $check->toArray(),
                    $checks,
                ),
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            foreach ($checks as $check) {
                $this->line(sprintf(
                    '[%s] %s: %s',
                    $check->passed
                        ? (string) trans('activity::activity/general.doctor.status.pass')
                        : mb_strtoupper($check->severity->getLabel()),
                    $check->key,
                    $check->message,
                ));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
