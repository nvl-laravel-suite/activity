<?php

declare(strict_types=1);

namespace Nvl\Activity\Data\Display;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Activity-owned structural payload for merged comment timeline rows.
 *
 * The Comments module remains the source of truth for comment rendering; this
 * DTO only documents the stable Activity payload shape without exporting the
 * Comments display DTO as part of the canonical Activity contract.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
final class ActivityCommentPayload extends Data
{
    use DataTransform;

    /**
     * Create a structural comment payload for Activity timeline properties.
     *
     * @param  string|Optional|null  $id  Comment UUID
     * @param  string|Optional|null  $content  Comment body
     * @param  string|Optional|null  $contentType  Comment body format
     * @param  string|Optional|null  $status  Comment moderation status
     * @param  string|Optional|null  $createdAt  Creation timestamp
     * @param  array<int, string>|Optional|null  $tagsArray  Normalized tag labels
     * @param  bool|Optional|null  $isPinned  Whether the comment is pinned
     * @param  bool|Optional|null  $isEdited  Whether the comment has been edited
     * @param  bool|Optional|null  $canUpdate  Whether the current user can update the comment
     * @param  bool|Optional|null  $canDelete  Whether the current user can delete the comment
     * @param  bool|Optional|null  $canModerate  Whether the current user can moderate the comment
     * @param  bool|Optional|null  $canPin  Whether the current user can pin the comment
     * @param  array<string, mixed>|Optional|null  $user  Comment author display payload
     * @param  array<int, array<string, mixed>>|Optional|null  $media  Attached media display payloads
     * @param  array<int, array<string, mixed>>|Optional|null  $attachments  Attached file display payloads
     */
    public function __construct(
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $id = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $content = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $contentType = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $status = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $createdAt = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string[] | null')]
        public readonly array|Optional|null $tagsArray = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean | null')]
        public readonly bool|Optional|null $isPinned = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean | null')]
        public readonly bool|Optional|null $isEdited = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean | null')]
        public readonly bool|Optional|null $canUpdate = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean | null')]
        public readonly bool|Optional|null $canDelete = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean | null')]
        public readonly bool|Optional|null $canModerate = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean | null')]
        public readonly bool|Optional|null $canPin = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        public readonly array|Optional|null $user = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown>[] | null')]
        public readonly array|Optional|null $media = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown>[] | null')]
        public readonly array|Optional|null $attachments = new Optional,
    ) {}
}
