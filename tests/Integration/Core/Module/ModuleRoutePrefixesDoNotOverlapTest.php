<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Core\Module;

use Aurora\Core\Module\Contract\ModuleInterface;
use Aurora\Core\Module\Contract\ModuleNavViewProviderInterface;
use Aurora\Core\Module\Nav\NavItem;
use Aurora\Tests\Integration\IntegrationTestCase;

use function sprintf;

/**
 * A module's menu entry claims every route that starts with its prefix, and
 * the side menu opens that module's own view on those routes. The Beacon
 * screen was named `dev_beacon`, so « Administration » (prefix `dev_`) took it
 * over: the page sat in its own « Protection » section yet opened the
 * administration menu. Every entry of the main menu must therefore stay out of
 * the prefixes of the modules that have a view of their own.
 */
final class ModuleRoutePrefixesDoNotOverlapTest extends IntegrationTestCase
{
    public function testNoMenuEntryFallsUnderAnotherModulesPrefix(): void
    {
        static::bootKernel();
        $container = static::getContainer();

        $modules = [];
        foreach (glob(__DIR__.'/../../../../src/Module/*/*Module.php') ?: [] as $file) {
            $class = 'Aurora\\Module\\'.basename(dirname($file)).'\\'.basename($file, '.php');
            if ($container->has($class) && ($module = $container->get($class)) instanceof ModuleInterface) {
                $modules[$module->getId()] = $module;
            }
        }

        self::assertArrayHasKey('dev', $modules);
        self::assertArrayHasKey('beacon', $modules);

        $overlaps = [];
        foreach ($modules as $ownerId => $owner) {
            if (!$owner instanceof ModuleNavViewProviderInterface) {
                continue;
            }

            $prefixes = $this->prefixes($owner);
            foreach ($modules as $otherId => $other) {
                if ($otherId === $ownerId) {
                    continue;
                }

                foreach ($this->routes($other) as $route) {
                    foreach ($prefixes as $prefix) {
                        if (str_starts_with($route, $prefix)) {
                            $overlaps[] = sprintf('%s (module %s) falls under « %s » of module %s', $route, $otherId, $prefix, $ownerId);
                        }
                    }
                }
            }
        }

        self::assertSame([], $overlaps);
    }

    /** @return list<string> */
    private function prefixes(ModuleInterface $module): array
    {
        $prefixes = [];
        foreach ($module->getCatalogNavSections() as $section) {
            foreach ($this->flatten($section->items) as $item) {
                $prefixes[] = $item->activeRoutePrefix ?? $item->route;
            }
        }

        return array_values(array_filter($prefixes, static fn (string $prefix): bool => '' !== $prefix));
    }

    /** @return list<string> */
    private function routes(ModuleInterface $module): array
    {
        $routes = [];
        foreach ($module->getCatalogNavSections() as $section) {
            foreach ($this->flatten($section->items) as $item) {
                $routes[] = $item->route;
            }
        }

        return $routes;
    }

    /**
     * @param list<NavItem> $items
     *
     * @return list<NavItem>
     */
    private function flatten(array $items): array
    {
        $flat = [];
        foreach ($items as $item) {
            $flat[] = $item;
            foreach ($this->flatten($item->children) as $child) {
                $flat[] = $child;
            }
        }

        return $flat;
    }
}
