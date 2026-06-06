<?php

declare(strict_types=1);

namespace Aurora\Core\Bundle;

use Override;
use ReflectionClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function dirname;

/**
 * Base bundle for a single self-contained Aurora module package.
 *
 * In the monorepo every module lives under `src/Module/<Name>/` and is wired
 * centrally by {@see AuroraBundle} via globbing. The monorepo-split
 * target instead ships each module as its own Composer package whose bundle
 * registers ONLY that module's Doctrine mappings, Twig namespace, translations
 * and `resolve_target_entities` — exactly what this base class does.
 *
 * A concrete module bundle just declares its name and its entity resolution
 * map; Symfony merges every bundle's prepended config, so a module bundle
 * coexists with the core bundle (and with other module bundles). A module that
 * is simply not registered in `bundles.php` contributes nothing — that is the
 * "install only what you want" mechanism.
 *
 * @see docs/aurora-core/dev/audit/poc_tools_bundle.md
 */
abstract class AbstractAuroraModuleBundle extends AbstractBundle
{
    /**
     * Module name as it appears under `src/Module/<Name>` and in the
     * `Aurora\Module\<Name>` namespace (e.g. 'Tools').
     */
    abstract protected function moduleName(): string;

    /**
     * Entity interface → concrete class map for this module's entities.
     * Mirrors the central `resolve_target_entities`, scoped to the module so
     * the client never edits a central file when (un)installing it.
     *
     * @return array<class-string, class-string>
     */
    abstract protected function resolveTargetEntities(): array;

    /**
     * Scope the bundle to its own dir (mirrors AuroraBundle::getPath) so
     * Symfony's assets:install doesn't treat the project public/ as the
     * bundle's Resources/public and recurse.
     */
    #[Override]
    public function getPath(): string
    {
        return $this->moduleDir();
    }

    /**
     * Load the module's own `config/services.php` when it ships one (standalone
     * Composer package). In the monorepo, modules without that file rely on the
     * central `Aurora\: resource` glob — so this is a no-op for them.
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $servicesFile = $this->moduleDir().'/config/services.php';
        if (is_file($servicesFile)) {
            $container->import($servicesFile);
        }

        // Dev/test only: register this module's DataFixtures as autoconfigured
        // services so `doctrine:fixtures:load` discovers them. Guarded by
        // class_exists so a prod (--no-dev) build never compiles fixture
        // classes — doctrine/doctrine-fixtures-bundle is a dev dependency, so
        // its Fixture base class is absent in prod. The module's own
        // services.php excludes DataFixtures to avoid a double registration.
        $env = (string) $builder->getParameter('kernel.environment');
        $fixturesDir = $this->moduleDir().'/DataFixtures';
        if (in_array($env, ['dev', 'test'], true)
            && is_dir($fixturesDir)
            && class_exists(\Doctrine\Bundle\FixturesBundle\Fixture::class)
        ) {
            $container->services()
                ->defaults()->autowire()->autoconfigure()
                ->load('Aurora\\Module\\'.$this->moduleName().'\\DataFixtures\\', $fixturesDir.'/');
        }
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $name = $this->moduleName();
        $moduleDir = $this->moduleDir();
        $projectDir = (string) $builder->getParameter('kernel.project_dir');

        // ── Doctrine: one mapping + this module's resolve_target_entities ──
        $builder->prependExtensionConfig('doctrine', [
            'orm' => [
                'resolve_target_entities' => $this->resolveTargetEntities(),
                'mappings' => [
                    'Aurora'.$name => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => $moduleDir,
                        'prefix' => 'Aurora\\Module\\'.$name,
                        'alias' => 'Aurora'.$name,
                    ],
                ],
            ],
        ]);

        // ── Twig: client overrides first (co-located + legacy), bundle last ──
        $twigPaths = [];
        $clientColocated = $projectDir.'/src/Module/'.$name.'/templates';
        $clientLegacy = $projectDir.'/templates/Module/'.$name;
        if ($clientColocated !== $moduleDir.'/templates' && is_dir($clientColocated)) {
            $twigPaths[$clientColocated] = $name;
        }

        if (is_dir($clientLegacy)) {
            $twigPaths[$clientLegacy] = $name;
        }

        $bundleTemplates = $moduleDir.'/templates';
        if (is_dir($bundleTemplates)) {
            $twigPaths[$bundleTemplates] = $name;
        }

        if ([] !== $twigPaths) {
            $builder->prependExtensionConfig('twig', ['paths' => $twigPaths]);
        }

        // ── Translations: module/translations + module/<sub>/translations ──
        $translationPaths = array_values(array_filter(
            array_merge(
                [$moduleDir.'/translations'],
                glob($moduleDir.'/*/translations', GLOB_ONLYDIR) ?: [],
            ),
            is_dir(...),
        ));

        if ([] !== $translationPaths) {
            $builder->prependExtensionConfig('framework', [
                'translator' => ['paths' => $translationPaths],
            ]);
        }
    }

    /**
     * Absolute path of the concrete bundle's directory — i.e. the module dir
     * (`src/Module/<Name>`), since the bundle class lives at the module root.
     */
    protected function moduleDir(): string
    {
        return dirname((string) new ReflectionClass(static::class)->getFileName());
    }
}
