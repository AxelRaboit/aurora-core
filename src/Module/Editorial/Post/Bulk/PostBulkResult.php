<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Bulk;

/**
 * What a bulk action actually did.
 *
 * Two numbers rather than a boolean, because "it worked" is not an answer a reader
 * can act on when a selection was partly refused. The screen says both, so somebody
 * who selected ten and changed eight finds that out here rather than by counting
 * rows afterwards.
 */
final readonly class PostBulkResult
{
    public function __construct(
        public int $done,
        public int $skipped,
    ) {}

    /** @return array<string, int> */
    public function toArray(): array
    {
        return ['done' => $this->done, 'skipped' => $this->skipped];
    }
}
