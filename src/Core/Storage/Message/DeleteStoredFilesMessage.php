<?php

declare(strict_types=1);

namespace Aurora\Core\Storage\Message;

/**
 * Delete these keys on every disk, out of the request.
 *
 * Every disk because a file written before a disk switch still lives on the
 * old one, and the key alone does not say which. For files nothing else can
 * name once their row is gone: there is no reference to check, the caller
 * decided they were orphans before its flush.
 */
final readonly class DeleteStoredFilesMessage
{
    /**
     * @param list<string> $keys
     */
    public function __construct(
        public array $keys,
    ) {}
}
