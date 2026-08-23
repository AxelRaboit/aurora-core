<?php

declare(strict_types=1);

namespace Aurora\Core\Bootstrap;

use ReflectionClass;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;

/**
 * Runs every bootstrap provider, highest priority first.
 *
 * Extracted from the install command because a second caller appeared: the
 * integration suite, which seeds its database the same way a real install
 * does. It used to name Core's provider on its own - correct when Core was
 * the only one, and quietly wrong once Editorial and GED had theirs. A test
 * database seeded differently from a real install is a test database that can
 * hide breakage, which is exactly what happened: an image upload filed into a
 * category the tests had never created.
 */
final readonly class BootstrapRunner
{
    /**
     * @param iterable<BootstrapProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('aurora.bootstrap_provider')]
        private iterable $providers,
    ) {}

    /**
     * Yields one entry per seeded row, and one per provider that failed.
     *
     * A provider that throws does not stop the others: a deploy needs to see
     * everything that is wrong, not just the first thing.
     *
     * @return iterable<BootstrapResult>
     */
    public function run(): iterable
    {
        $providers = iterator_to_array($this->providers, false);
        usort(
            $providers,
            static fn (BootstrapProviderInterface $a, BootstrapProviderInterface $b): int => $b->getPriority() <=> $a->getPriority(),
        );

        foreach ($providers as $provider) {
            try {
                foreach ($provider->bootstrap() as $label) {
                    yield BootstrapResult::created((string) $label);
                }
            } catch (Throwable $e) {
                yield BootstrapResult::failed(
                    new ReflectionClass($provider)->getShortName(),
                    $e->getMessage(),
                );
            }
        }
    }
}
