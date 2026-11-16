<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Nvl\Activity\Exceptions\ActivityRecordingException;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Services\ActivityRecorder;
use Nvl\Activity\Support\ActivityRecordEnvelope;
use Nvl\Activity\Support\ActivitySubjectReference;

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Real Activity delivery races require the native PostgreSQL database runner.');
    }
    if (! function_exists('pcntl_fork') || ! function_exists('pcntl_exec')) {
        $this->markTestSkipped('Real Activity delivery races require pcntl worker processes.');
    }
});

/**
 * Run each worker in its own database process from a common start barrier and return its outcome.
 *
 * @param  list<Closure(int, string): void>  $workers
 * @return list<array{ok: bool, error?: string, message?: string}>
 */
function activityEnvelopeRace(array $workers): array
{
    $directory = sys_get_temp_dir().'/nvl-activity-race-'.bin2hex(random_bytes(8));
    mkdir($directory);
    $connection = DB::getDefaultConnection();
    $children = [];
    try {
        foreach ($workers as $index => $worker) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Could not fork an Activity race worker.');
            }
            if ($pid > 0) {
                $children[] = $pid;

                continue;
            }
            DB::purge($connection);
            try {
                file_put_contents($directory.'/ready-'.$index, 'ready');
                $deadline = microtime(true) + 8;
                while (! is_file($directory.'/go')) {
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException('Activity race start barrier timed out.');
                    }
                    usleep(1000);
                }
                $worker($index, $directory);
                $result = ['ok' => true];
            } catch (Throwable $error) {
                $result = ['ok' => false, 'error' => $error::class, 'message' => $error->getMessage()];
            }
            file_put_contents($directory.'/result-'.$index, json_encode($result, JSON_THROW_ON_ERROR));
            pcntl_exec('/usr/bin/true');
            exit(1);
        }
        $deadline = microtime(true) + 8;
        while (count(glob($directory.'/ready-*')) !== count($workers)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Activity race workers failed to reach the start barrier.');
            }
            usleep(1000);
        }
        file_put_contents($directory.'/go', 'go');
        foreach ($children as $pid) {
            $deadline = microtime(true) + 15;
            while (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Activity race worker failed to finish within 15 seconds.');
                }
                usleep(1000);
            }
            expect(pcntl_wifexited($status))->toBeTrue()->and(pcntl_wexitstatus($status))->toBe(0);
        }
        DB::purge($connection);

        return array_map(fn (int $index): array => json_decode(file_get_contents($directory.'/result-'.$index), true, flags: JSON_THROW_ON_ERROR), array_keys($workers));
    } finally {
        foreach ($children as $pid) {
            if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
        }
        foreach (glob($directory.'/*') as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
}

function activityRaceEnvelope(string $id, string $subject, string $state): ActivityRecordEnvelope
{
    return new ActivityRecordEnvelope(
        id: $id,
        subject: new ActivitySubjectReference('external', $subject),
        causer: null,
        event: 'reviewed',
        logName: 'deliveries',
        occurredAt: CarbonImmutable::parse('2026-11-16T10:11:12.123456+00:00'),
        context: ['state' => $state],
    );
}

/**
 * Deliver one envelope inside an outer transaction once both workers passed the existence check, then record a
 * follow-up fact in the same transaction to prove a lost race leaves it usable.
 */
function activityEnvelopeWorker(ActivityRecordEnvelope $envelope): Closure
{
    return function (int $index, string $directory) use ($envelope): void {
        $paused = false;
        DB::connection()->beforeExecuting(function (string $query) use (&$paused, $directory, $index): void {
            if ($paused || ! str_starts_with(strtolower($query), 'insert into') || ! str_contains($query, (new ActivityLog)->getTable())) {
                return;
            }
            $paused = true;
            file_put_contents($directory.'/insert-'.$index, 'insert');
            $deadline = microtime(true) + 5;
            while (! is_file($directory.'/insert-'.(1 - $index))) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Activity race insert barrier timed out.');
                }
                usleep(1000);
            }
        });
        $conflict = null;
        DB::transaction(function () use ($envelope, $index, &$conflict): void {
            $recorder = app(ActivityRecorder::class);
            try {
                $recorder->recordEnvelope($envelope);
            } catch (ActivityRecordingException $exception) {
                $conflict = $exception;
            }
            $recorder->recordEnvelope(activityRaceEnvelope((string) str()->uuid(), 'follow-up-'.$index, 'after'));
        });
        if ($conflict !== null) {
            throw $conflict;
        }
    };
}

it('concurrent identical deliveries converge; conflicting envelopes fail without poisoning the outer transaction', function (bool $conflicting): void {
    $id = (string) str()->uuid();
    $envelopes = [activityRaceEnvelope($id, 'one', 'active'), activityRaceEnvelope($id, 'one', $conflicting ? 'closed' : 'active')];

    $results = activityEnvelopeRace(array_map(activityEnvelopeWorker(...), $envelopes));

    $stored = ActivityLog::query()->findOrFail($id);
    $followUps = ActivityLog::query()->whereIn('subject_id', ['follow-up-0', 'follow-up-1'])->pluck('subject_id')->sort()->values()->all();
    expect(ActivityLog::query()->count())->toBe(3)
        ->and($followUps)->toBe(['follow-up-0', 'follow-up-1']);
    if (! $conflicting) {
        expect(array_column($results, 'ok'))->toBe([true, true])
            ->and($stored->properties->get('context'))->toBe(['state' => 'active']);

        return;
    }
    $winner = array_search(true, array_column($results, 'ok'), true);
    expect(array_column($results, 'ok'))->toContain(true, false)
        ->and(array_values(array_filter(array_column($results, 'error'))))->toBe([ActivityRecordingException::class])
        ->and($winner)->toBeInt()
        ->and($stored->properties->get('context'))->toBe($envelopes[$winner]->context);
})->with(['identical deliveries' => [false], 'conflicting envelopes' => [true]]);
