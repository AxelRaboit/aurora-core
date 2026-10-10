<?php

declare(strict_types=1);

namespace Aurora\Core\Module\Nav;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Writes each menu entry's figure into the resolved menu, as `count`, and what
 * waits on somebody behind it, as `attention`.
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
     * @param iterable<NavItemCountProviderInterface>     $providers
     * @param iterable<NavItemAttentionProviderInterface> $attentionProviders
     */
    public function __construct(
        #[AutowireIterator(NavItemCountProviderInterface::TAG)]
        private iterable $providers,
        #[AutowireIterator(NavItemAttentionProviderInterface::TAG)]
        private iterable $attentionProviders = [],
    ) {}

    /**
     * @param list<array<string, mixed>> $items resolved entries, children included
     *
     * @return list<array<string, mixed>>
     */
    public function annotateItems(array $items): array
    {
        return $this->annotate($items, $this->providerByKey(), $this->attentionProviderByKey());
    }

    /**
     * @param list<array<string, mixed>> $containers sections or groups, each with an `items` list
     *
     * @return list<array<string, mixed>>
     */
    public function annotateContainers(array $containers): array
    {
        $providerByKey = $this->providerByKey();
        $attentionProviderByKey = $this->attentionProviderByKey();

        return array_map(
            fn (array $container): array => ['items' => $this->annotate($container['items'] ?? [], $providerByKey, $attentionProviderByKey)] + $container,
            $containers,
        );
    }

    /**
     * @param list<array<string, mixed>>                       $items
     * @param array<string, NavItemCountProviderInterface>     $providerByKey
     * @param array<string, NavItemAttentionProviderInterface> $attentionProviderByKey
     *
     * @return list<array<string, mixed>>
     */
    private function annotate(array $items, array $providerByKey, array $attentionProviderByKey): array
    {
        $annotated = [];
        foreach ($items as $item) {
            $key = $item['key'] ?? null;
            if (is_string($key) && isset($providerByKey[$key])) {
                $item['count'] = $providerByKey[$key]->countItem($key);
            }

            // Only when there is something to do: a pill reading zero would be
            // a reminder of nothing.
            if (is_string($key) && isset($attentionProviderByKey[$key])) {
                $attention = $attentionProviderByKey[$key]->countAttention($key);

                if ($attention > 0) {
                    $item['attention'] = [
                        'count' => $attention,
                        'labelKey' => $attentionProviderByKey[$key]->getAttentionLabelKey($key),
                    ];
                }
            }

            if ([] !== ($item['children'] ?? [])) {
                $item['children'] = $this->annotate($item['children'], $providerByKey, $attentionProviderByKey);
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

    /**
     * @return array<string, NavItemAttentionProviderInterface>
     */
    private function attentionProviderByKey(): array
    {
        $providerByKey = [];
        foreach ($this->attentionProviders as $provider) {
            foreach ($provider->getAttentionItemKeys() as $key) {
                $providerByKey[$key] = $provider;
            }
        }

        return $providerByKey;
    }
}
