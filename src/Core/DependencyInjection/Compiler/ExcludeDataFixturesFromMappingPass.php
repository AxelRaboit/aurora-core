<?php

declare(strict_types=1);

namespace Aurora\Core\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function dirname;

/**
 * Keeps Doctrine's attribute driver out of the DataFixtures directories.
 *
 * The ORM mappings declared in AuroraBundle point at `src/Core` and each
 * `src/Module/<Name>`, and the attribute driver walks those directories file by
 * file: getAllClassNames() autoloads every class it finds before asking whether
 * it is an entity. Fixtures live inside those directories and extend
 * doctrine/doctrine-fixtures-bundle's Fixture, which is a dev dependency. In a
 * `composer install --no-dev` build the parent class is gone, so merely listing
 * the mapped classes fatals with "Class Doctrine\Bundle\FixturesBundle\Fixture
 * not found" - taking down doctrine:schema:create and any cache warmup that
 * touches ORM metadata.
 *
 * config/services.yaml already gates the same directories for the service
 * container (excluded from the `Aurora\` glob, re-registered under when@dev).
 * This pass is the mapping-side half of that gating. Fixtures carry no ORM
 * attributes, so excluding them costs nothing in dev either.
 */
final class ExcludeDataFixturesFromMappingPass implements CompilerPassInterface
{
    private const string DRIVER_ID = 'doctrine.orm.default_attribute_metadata_driver';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::DRIVER_ID)) {
            return;
        }

        $paths = $this->dataFixturesPaths();

        if ([] === $paths) {
            return;
        }

        $container->getDefinition(self::DRIVER_ID)
            ->addMethodCall('addExcludePaths', [$paths]);
    }

    /**
     * @return list<string>
     */
    private function dataFixturesPaths(): array
    {
        $src = dirname(__DIR__, 3);

        $paths = glob($src.'/Module/*/DataFixtures', GLOB_ONLYDIR) ?: [];
        array_unshift($paths, $src.'/Core/DataFixtures');

        return array_values(array_filter($paths, is_dir(...)));
    }
}
