<?php

declare(strict_types=1);

namespace Nvl\Activity\Tests\Fixtures;

use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Contracts\TenantQueuedJob;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\ValueObjects\TenantJobEnvelope;

/** Carries value-free setting mutation facts and producer ownership across serialization. */
final readonly class ActivitySettingChanged implements TenantQueuedJob
{
    public ActivitySubjectReference $subject;

    private TenantJobEnvelope $envelope;

    /** Capture scalar setting identity and the admitted producer scope at dispatch construction. */
    public function __construct(
        public string $id,
        public string $key,
        public int $revision,
        public string $operation,
    ) {
        $context = app(TenantContext::class);
        if ($context->snapshot()->mode === TenantContextMode::Platform) {
            throw new TenantBoundaryViolation('Platform setting facts must be recorded synchronously.');
        }
        $this->subject = new ActivitySubjectReference('nvl_setting', $id);
        $this->envelope = TenantJobEnvelope::capture($context);
    }

    /** Return the immutable producer ownership carried by Laravel's queued-listener wrapper. */
    public function tenantJobEnvelope(): TenantJobEnvelope
    {
        return $this->envelope;
    }
}
