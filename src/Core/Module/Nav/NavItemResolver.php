<?php

declare(strict_types=1);

namespace Aurora\Core\Module\Nav;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Turns a declared {@see NavItem} into the array the menu renders: privilege
 * checked, path generated, children resolved, user-hidden entries dropped.
 *
 * Extracted from `ModuleRegistry` when the module view arrived, because the
 * module view needs the exact same treatment for its own links. Two copies of
 * this would have drifted on the first change - and the first change would
 * have been a security one, since this is where `requiredPrivilege` is
 * enforced.
 */
final readonly class NavItemResolver
{
    public function __construct(
        private AuthorizationCheckerInterface $security,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    /**
     * @param list<string> $hiddenItems user-hidden NavItem route names
     *
     * @return array<string, mixed>|null null when the item is filtered by role or hidden by the user
     */
    public function resolve(NavItem $item, array $hiddenItems = []): ?array
    {
        if (null !== $item->requiredPrivilege && !$this->security->isGranted($item->requiredPrivilege)) {
            return null;
        }

        if (in_array($item->route, $hiddenItems, true)) {
            return null;
        }

        $children = [];
        foreach ($item->children as $child) {
            $resolved = $this->resolve($child, $hiddenItems);
            if (null !== $resolved) {
                $children[] = $resolved;
            }
        }

        return [
            'key' => $item->route,
            'route' => $item->activeRoutePrefix ?? $item->route,
            'path' => $this->urlGenerator->generate($item->route),
            'labelKey' => $item->labelKey,
            'descriptionKey' => $item->descriptionKey,
            'icon' => $item->icon,
            'activeColor' => $item->activeColor,
            'children' => $children,
        ];
    }

    /**
     * Resolves a list, dropping whatever came back null.
     *
     * @param NavItem[]    $items
     * @param list<string> $hiddenItems
     *
     * @return list<array<string, mixed>>
     */
    public function resolveAll(array $items, array $hiddenItems = []): array
    {
        $resolved = [];
        foreach ($items as $item) {
            $one = $this->resolve($item, $hiddenItems);
            if (null !== $one) {
                $resolved[] = $one;
            }
        }

        return $resolved;
    }
}
