<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Menu\Contract;

/**
 * A slot a theme renders a menu into — a header bar, a footer column, an
 * account dropdown. The key is what a template passes to `menu_items()`.
 */
final readonly class MenuLocation
{
    /** @param list<DefaultMenuItem> $defaultItems */
    public function __construct(
        public string $key,
        public string $labelKey,
        public ?string $descriptionKey = null,
        public array $defaultItems = [],
    ) {}
}
