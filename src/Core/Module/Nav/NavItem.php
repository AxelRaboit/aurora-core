<?php

declare(strict_types=1);

namespace Aurora\Core\Module\Nav;

final readonly class NavItem
{
    /**
     * @param string               $route       Symfony route name. **Stable token**: serves as the immutable
     *                                          identifier for persisting user preferences
     *                                          (`CoreUserInterface::getHiddenNavItems()`). Renaming a route =
     *                                          breaking change (the users' preference is silently lost).
     * @param NavItem[]            $children
     * @param array<string, mixed> $routeParams Parameters passed to the URL generator. Not empty = several
     *                                          entries share the same route name (the settings tabs are
     *                                          eleven entries on `..._settings_tab`), and two things then
     *                                          change: a distinct `$key` is needed, and the active entry is
     *                                          recognised by its path, not by its route name.
     * @param ?string              $label       **Literal** label, already readable, when the entry's name is
     *                                          data and not a word of the interface: a content type is
     *                                          called "Article" because someone typed it. `labelKey` stays
     *                                          mandatory and serves as the fallback; passing the label in it
     *                                          would ask `t()` to translate data, which would return the
     *                                          string as is with a warning on every render.
     * @param ?string              $description **Literal** secondary line, same reason as `$label`: what tells
     *                                          two records apart is their data - a slug, a location - and
     *                                          not a sentence of the interface. It is what the selection
     *                                          column showed under the name before it went away.
     * @param ?string              $key         Stable identifier, when the route name is not one.
     *                                          Default: the route name, what it has always been. When set,
     *                                          it becomes what the user preferences persist - so it is as
     *                                          immutable as a route name. Without it, hiding one settings
     *                                          tab would hide all eleven.
     */
    public function __construct(
        public string $route,
        public string $labelKey,
        public string $icon,
        public ?string $requiredPrivilege = null,
        public string $activeColor = 'accent',
        public ?string $activeRoutePrefix = null,
        public array $children = [],
        public ?string $descriptionKey = null,
        public array $routeParams = [],
        public ?string $key = null,
        public ?string $label = null,
        public ?string $description = null,
    ) {}

    /** What the user preferences persist, and what the palette indexes. */
    public function stableKey(): string
    {
        return $this->key ?? $this->route;
    }
}
