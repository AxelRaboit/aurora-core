<?php

declare(strict_types=1);

namespace Aurora\Core\Module\Nav;

final readonly class NavSection
{
    /**
     * @param string    $id       Stable technical identifier (e.g. `'crm'`, `'billing.invoicing'`).
     *                            **Immutable**: used to persist user preferences
     *                            (`CoreUserInterface::getHiddenNavSections()`). Renaming an `id` =
     *                            breaking change (same as deleting the section + creating a new one).
     *                            The visible label comes from i18n / aliases, never from here.
     * @param NavItem[] $items
     * @param int       $priority Lower = renders first. Defaults to 100. Use higher values (e.g. 1000)
     *                            for system/meta sections that should sit at the bottom of the nav.
     */
    public function __construct(
        public string $id,
        public array $items,
        public int $priority = 100,
    ) {}
}
