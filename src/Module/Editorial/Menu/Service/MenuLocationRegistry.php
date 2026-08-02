<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Menu\Service;

use Aurora\Module\Editorial\Menu\Contract\MenuLocation;
use Aurora\Module\Editorial\Menu\Contract\MenuLocationProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The menu locations the application knows about, gathered from every module
 * that declares some.
 */
final class MenuLocationRegistry
{
    /** @var array<string, MenuLocation>|null */
    private ?array $locations = null;

    /** @param iterable<MenuLocationProviderInterface> $providers */
    public function __construct(
        #[AutowireIterator('aurora.menu_location_provider')]
        private readonly iterable $providers,
    ) {}

    /** @return array<string, MenuLocation> */
    public function all(): array
    {
        if (null !== $this->locations) {
            return $this->locations;
        }

        $locations = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->getMenuLocations() as $location) {
                $locations[$location->key] = $location;
            }
        }

        return $this->locations = $locations;
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    public function get(string $key): ?MenuLocation
    {
        return $this->all()[$key] ?? null;
    }
}
