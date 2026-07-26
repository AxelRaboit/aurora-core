<?php

declare(strict_types=1);

namespace Aurora\Module\Dev\Prerequisite;

/**
 * One missing-prerequisite detected by {@see DevPrerequisiteChecker}.
 * Surfaced as a dev-only banner in the admin layout.
 */
final readonly class PrerequisiteWarning
{
    public function __construct(
        /** Short message shown in the banner. */
        public string $message,
        /** The shell command (or human instruction) that fixes the issue. */
        public string $fix,
        /**
         * `warning` = blocks real functionality (missing PHP extension, missing Node).
         * `info`    = optional or best-effort.
         */
        public string $level = 'warning',
    ) {}
}
