<?php

declare(strict_types=1);

namespace Aurora\Core\Reference;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Aggregates every module-contributed {@see EntityReferenceProviderInterface}
 * and resolves soft cross-module references by type. Lets a module read a
 * reference it holds (gallery → contact, project → company, …) without
 * importing the owning module - and answer "is that module installed?" via
 * {@see supports()}.
 */
final readonly class EntityReferenceResolver
{
    /**
     * @param iterable<EntityReferenceProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('aurora.entity_reference_provider')]
        private iterable $providers,
    ) {}

    /** True when a module provides this reference type (i.e. it is installed). */
    public function supports(string $type): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->getType() === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * Display-ready summary of the referenced entity, or null (unknown type,
     * null id, owning module absent, or entity gone).
     *
     * @return array<string, mixed>|null
     */
    public function summarize(string $type, ?int $id): ?array
    {
        if (null === $id) {
            return null;
        }

        foreach ($this->providers as $provider) {
            if ($provider->getType() === $type) {
                return $provider->summarize($id);
            }
        }

        return null;
    }

    /**
     * All selectable options of a reference type (for a picker), or [] when the
     * owning module is absent.
     *
     * @return list<array{id: int, name: string}>
     */
    public function options(string $type): array
    {
        foreach ($this->providers as $provider) {
            if ($provider->getType() === $type) {
                return $provider->options();
            }
        }

        return [];
    }
}
