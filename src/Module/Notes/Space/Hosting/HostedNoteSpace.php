<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Hosting;

use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;

/** A hosted space, as its host opened it for a request. */
final readonly class HostedNoteSpace
{
    public function __construct(
        public NoteSpaceInterface $space,
        public NoteSpaceHostInterface $host,
        public string $reference,
    ) {}
}
