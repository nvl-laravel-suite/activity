<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Nvl\Activity\Services\ActivityRecorder;
use Nvl\Tenancy\Services\TenantQueueContext;

/** Persists a deferred value-free setting fact inside its captured tenant scope. */
final readonly class RecordActivitySettingChanged implements ShouldQueue
{
    /** Create the listener from scoped Activity and queue-context services. */
    public function __construct(
        private ActivityRecorder $recorder,
        private TenantQueueContext $queueContext,
    ) {}

    /** Record the setting mutation without loading Settings or serializing its value. */
    public function handle(ActivitySettingChanged $event): void
    {
        $this->queueContext->run($event->tenantJobEnvelope(), fn () => $this->recorder->recordForSubjectReference(
            subject: $event->subject,
            event: $event->operation,
            context: [
                'key' => $event->key,
                'revision' => $event->revision,
            ],
        ));
    }
}
