<?php

declare(strict_types=1);

namespace Aurora\Core\Module\Nav;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Writes each menu entry's figure into the resolved menu, as `count`.
 *
 * Works on the arrays {@see NavItemResolver} produces, after privileges and
 * the reader's hidden entries have been applied, so only entries that will be
 * drawn are counted. An entry no provider counts keeps no `count` at all,
 * which is how the menu tells "nothing to count" from "zero".
 *
 * Read by the main menu and by a module's own view alike: the same entry shows
 * the same figure in both.
 */
final readonly class NavItemCounter
{
    /**
     * @param iterable<NavItemCountProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator(NavItemCountProviderInterface::TAG)]
        private iterable $providers,
    ) {}

    /**
     * @param list<array<string, mixed>> $items resolved entries, children included
     *
     * @return list<array<string, mixed>>
     */
    public function annotateItems(array $items): array
    {
        $providerByKey = $this->providerByKey();

        return $this->annotate($items, $providerByKey);
    }

    /**
     * @param list<array<string, mixed>> $containers sections or groups, each with an `items` list
     *
     * @return list<array<string, mixed>>
     */
    public function annotateContainers(array $containers): array
    {
        $providerByKey = $this->providerByKey();

        return array_map(
            fn (array $container): array => ['items' => $this->annotate($container['items'] ?? [], $providerByKey)] + $container,
            $containers,
        );
    }

    /**
     * @param list<array<string, mixed>>                   $items
     * @param array<string, NavItemCountProviderInterface> $providerByKey
     *
     * @return list<array<string, mixed>>
     */
    private function annotate(array $items, array $providerByKey): array
    {
        $annotated = [];
        foreach ($items as $item) {
            $key = $item['key'] ?? null;
            if (is_string($key) && isset($providerByKey[$key])) {
                $item['count'] = $providerByKey[$key]->countItem($key);
            }

            if ([] !== ($item['children'] ?? [])) {
                $item['children'] = $this->annotate($item['children'], $providerByKey);
            }

            $annotated[] = $item;
        }

        return $annotated;
    }

    /**
     * @return array<string, NavItemCountProviderInterface>
     */
    private function providerByKey(): array
    {
        $providerByKey = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->getCountedItemKeys() as $key) {
                $providerByKey[$key] = $provider;
            }
        }

        return $providerByKey;
    }
}
