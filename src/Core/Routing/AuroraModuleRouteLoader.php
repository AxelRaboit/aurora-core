<?php

declare(strict_types=1);

namespace Aurora\Core\Routing;

use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\RouteCollection;

use function basename;
use function mb_rtrim;

/**
 * Auto-discovers controllers of every installed Aurora module package
 * (vendor/axelraboit/aurora-*) so a client declares ONE routing entry instead
 * of one `resource:` line per installed module:
 *
 *     # config/routes.yaml
 *     aurora_modules:
 *         resource: .
 *         type: aurora_modules
 *
 * Each package dir is imported with the standard `attribute` loader (same as a
 * hand-written `resource: '../vendor/axelraboit/aurora-x/' type: attribute`).
 * In the monorepo the glob matches nothing (modules live in src/), so this
 * yields an empty collection - harmless to keep the entry everywhere.
 */
final class AuroraModuleRouteLoader extends Loader
{
    public function __construct(private readonly string $projectDir, ?string $env = null)
    {
        parent::__construct($env);
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $collection = new RouteCollection();

        foreach (glob($this->projectDir.'/vendor/axelraboit/aurora-*', GLOB_ONLYDIR) ?: [] as $dir) {
            // aurora-core's own controllers are loaded by the client's `aurora`
            // entry; skip it here to avoid double registration.
            if ('aurora-core' === basename(mb_rtrim($dir, '/'))) {
                continue;
            }

            $imported = $this->import($dir, 'attribute');
            $collection->addCollection($imported);
        }

        return $collection;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return 'aurora_modules' === $type;
    }
}
