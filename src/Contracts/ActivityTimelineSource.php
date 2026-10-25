<?php

declare(strict_types=1);

namespace Nvl\Activity\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Activity\Support\ActivitySubjectReference;

/**
 * Host-owned timeline source; supplies a subject-constrained query, never a presentation DTO.
 *
 * @template TModel of Model
 *
 * @api
 */
interface ActivityTimelineSource
{
    /**
     * Return canonical records with created_at and a primary key, constrained to this subject.
     * Activity adds current ownership admission and a bounded deterministic order.
     *
     * @return Builder<TModel>
     */
    public function query(Model|ActivitySubjectReference $subject): Builder;

    /** @return list<string> Base events replaced only when this source has visible records. */
    public function supersedes(): array;
}
