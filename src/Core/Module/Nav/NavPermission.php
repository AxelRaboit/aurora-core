<?php

declare(strict_types=1);

namespace Aurora\Core\Module\Nav;

final readonly class NavPermission
{
    public function __construct(
        public string $name,
        /**
         * Optional override for the group the permission is shown under in
         * the privileges modal. Defaults to the declaring module's id
         * (via `ModuleInterface::getId()`). Rarely needed since Jalon 4
         * split CoreModule into one class per section - each module's
         * permissions naturally land under its own group. Kept as a
         * safety valve for cross-cutting privileges that conceptually
         * belong elsewhere than the class declaring them.
         */
        public ?string $group = null,
    ) {}
}
