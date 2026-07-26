<?php

declare(strict_types=1);

namespace Aurora;

use Aurora\Core\Encryption\Doctrine\EncryptedStringType;
use Aurora\Core\Encryption\Doctrine\EncryptedTextType;
use Aurora\Core\Locale\Entity\Locale;
use Aurora\Core\Locale\Entity\LocaleInterface;
use Aurora\Core\Locale\Enum\LocaleEnum;
use Aurora\Core\Notification\Entity\Notification;
use Aurora\Core\Notification\Entity\NotificationInterface;
use Aurora\Module\Configuration\Setting\Entity\Setting;
use Aurora\Module\Configuration\Setting\Entity\SettingInterface;
use Aurora\Module\Configuration\Theme\Entity\Theme;
use Aurora\Module\Configuration\Theme\Entity\ThemeInterface;
use Aurora\Module\Dev\Audit\Entity\AuditLog;
use Aurora\Module\Dev\Audit\Entity\AuditLogInterface;
use Aurora\Module\Dev\MountPoint\Entity\MountPoint;
use Aurora\Module\Dev\MountPoint\Entity\MountPointInterface;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Entity\DocumentVersion;
use Aurora\Module\Ged\Document\Entity\DocumentVersionInterface;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategory;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategoryInterface;
use Aurora\Module\Ged\DocumentFolder\Entity\DocumentFolder;
use Aurora\Module\Ged\DocumentFolder\Entity\DocumentFolderInterface;
use Aurora\Module\Ged\DocumentTag\Entity\DocumentTag;
use Aurora\Module\Ged\DocumentTag\Entity\DocumentTagInterface;
use Aurora\Module\Platform\Auth\Entity\AccessRequest;
use Aurora\Module\Platform\Auth\Entity\AccessRequestInterface;
use Aurora\Module\Platform\Auth\Entity\ResetPasswordRequest;
use Aurora\Module\Platform\Auth\Entity\ResetPasswordRequestInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Override;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class AuroraBundle extends AbstractBundle
{
    /**
     * Override AbstractBundle::getPath() — the default returns
     * `dirname(file, 2)` which resolves to the project root (or vendor
     * package root when used by a client). That makes Symfony's
     * `assets:install` treat the project's `public/` as the bundle's
     * `Resources/public` and copy it recursively into
     * `public/bundles/aurora/` — infinite nesting.
     *
     * Returning `__DIR__` (the `src/` dir) scopes the bundle to its
     * code dir; no `src/public/` exists, so no asset copy happens.
     * All internal paths in this bundle use `dirname(__DIR__)` directly,
     * so the override doesn't affect translations / Doctrine mappings /
     * Twig namespaces — they still resolve against the project root.
     */
    #[Override]
    public function getPath(): string
    {
        return __DIR__;
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import(dirname(__DIR__).'/config/services.yaml');
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $dir = dirname(__DIR__);

        // Modules extracted into their own bundle (POC for the monorepo split):
        // AuroraBundle ignores them entirely — their dedicated
        // Aurora<Name>Bundle registers their Doctrine mapping / Twig / i18n /
        // resolve_target_entities. In the target topology these dirs live in a
        // separate Composer package and simply aren't present here; the list
        // simulates that absence inside the monorepo.
        $extractedModules = ['Editorial'];

        $moduleDirs = array_values(array_filter(
            glob($dir.'/src/Module/*', GLOB_ONLYDIR) ?: [],
            static fn (string $moduleDir): bool => !in_array(basename($moduleDir), $extractedModules, true),
        ));

        $builder->prependExtensionConfig('doctrine', [
            'dbal' => [
                'types' => [
                    EncryptedTextType::NAME => EncryptedTextType::class,
                    EncryptedStringType::NAME => EncryptedStringType::class,
                ],
            ],
            'orm' => [
                'validate_xml_mapping' => true,
                'naming_strategy' => 'doctrine.orm.naming_strategy.underscore',
                'identity_generation_preferences' => [
                    PostgreSQLPlatform::class => 'identity',
                ],
                'auto_mapping' => false,
                'resolve_target_entities' => [
                    CoreUserInterface::class => User::class,
                    AuditLogInterface::class => AuditLog::class,
                    AccessRequestInterface::class => AccessRequest::class,
                    ResetPasswordRequestInterface::class => ResetPasswordRequest::class,
                    LocaleInterface::class => Locale::class,
                    NotificationInterface::class => Notification::class,
                    SettingInterface::class => Setting::class,
                    ThemeInterface::class => Theme::class,
                    DocumentInterface::class => Document::class,
                    DocumentVersionInterface::class => DocumentVersion::class,
                    DocumentCategoryInterface::class => DocumentCategory::class,
                    DocumentTagInterface::class => DocumentTag::class,
                    DocumentFolderInterface::class => DocumentFolder::class,
                    MountPointInterface::class => MountPoint::class,
                ],
                'mappings' => array_merge(
                    [
                        'AuroraCore' => [
                            'type' => 'attribute',
                            'is_bundle' => false,
                            'dir' => $dir.'/src/Core',
                            'prefix' => 'Aurora\Core',
                            'alias' => 'AuroraCore',
                        ],
                    ],
                    ...array_map(static function (string $moduleDir): array {
                        $moduleName = basename($moduleDir);

                        return [
                            'Aurora'.$moduleName => [
                                'type' => 'attribute',
                                'is_bundle' => false,
                                'dir' => $moduleDir,
                                'prefix' => 'Aurora\\Module\\'.$moduleName,
                                'alias' => 'Aurora'.$moduleName,
                            ],
                        ];
                    }, $moduleDirs),
                ),
            ],
        ]);

        // Client templates take priority over Aurora's. For each Aurora namespace
        // we prepend the client-side path(s) first; the bundle path is registered
        // last as the fallback. Client overrides are recognized in two locations
        // for each namespace — the new co-located path (mirroring core's layout
        // since templates were moved under src/) AND the legacy top-level path
        // (kept for backward compat with existing client projects).
        $projectDir = (string) $builder->getParameter('kernel.project_dir');

        $twigPaths = [];

        // 1. Client-side overrides (highest priority — registered first).
        foreach ($moduleDirs as $moduleDir) {
            $moduleName = basename($moduleDir);
            $clientColocated = $projectDir.'/src/Module/'.$moduleName.'/templates';
            $clientLegacy = $projectDir.'/templates/Module/'.$moduleName;
            // Don't double-register when $projectDir === $dir (aurora-core dev mode).
            if ($clientColocated !== $dir.'/src/Module/'.$moduleName.'/templates' && is_dir($clientColocated)) {
                $twigPaths[$clientColocated] = $moduleName;
            }

            if (is_dir($clientLegacy)) {
                $twigPaths[$clientLegacy] = $moduleName;
            }
        }

        if ($projectDir !== $dir) {
            foreach (['Core', 'Shared'] as $namespace) {
                $clientColocated = $projectDir.'/src/Core/templates/'.$namespace;
                $clientLegacy = $projectDir.'/templates/'.$namespace;
                if (is_dir($clientColocated)) {
                    $twigPaths[$clientColocated] = $namespace;
                }

                if (is_dir($clientLegacy)) {
                    $twigPaths[$clientLegacy] = $namespace;
                }
            }
        }

        // 2. Bundle defaults (lowest priority — registered last).
        // Null namespace covers both the bundle's src/Core/templates/ (so
        // relative refs like 'Frontend/themes/default/...' still resolve) and
        // the legacy <bundle>/templates/ (still hosts templates/bundles/TwigBundle/
        // for Symfony's third-party override convention).
        $twigPaths[$dir.'/src/Core/templates'] = null;
        $twigPaths[$dir.'/templates'] = null;
        $twigPaths[$dir.'/src/Core/assets/css'] = 'styles';
        foreach (['Core', 'Shared'] as $namespace) {
            $bundleColocated = $dir.'/src/Core/templates/'.$namespace;
            if (is_dir($bundleColocated)) {
                $twigPaths[$bundleColocated] = $namespace;
            }
        }

        foreach ($moduleDirs as $moduleDir) {
            $moduleName = basename($moduleDir);
            $bundleModuleTemplates = $moduleDir.'/templates';
            if (is_dir($bundleModuleTemplates)) {
                $twigPaths[$bundleModuleTemplates] = $moduleName;
            }
        }

        $builder->prependExtensionConfig('twig', [
            'file_name_pattern' => '*.twig',
            'paths' => $twigPaths,
        ]);

        $builder->prependExtensionConfig('doctrine_migrations', [
            'migrations_paths' => [
                'DoctrineMigrations' => $dir.'/migrations',
            ],
            'enable_profiler' => false,
        ]);

        $coreDirs = array_merge(
            glob($dir.'/src/Core/*/translations', GLOB_ONLYDIR) ?: [],
            glob($dir.'/src/Core/*/*/translations', GLOB_ONLYDIR) ?: [],
        );

        $builder->prependExtensionConfig('framework', [
            'default_locale' => LocaleEnum::default()->value,
            'enabled_locales' => LocaleEnum::values(),
            'translator' => [
                'default_path' => $dir.'/src/Core/translations',
                'paths' => array_values(array_filter(
                    array_merge(
                        array_map(static fn (string $moduleDir): string => $moduleDir.'/translations', $moduleDirs),
                        glob($dir.'/src/Module/*/*/translations', GLOB_ONLYDIR) ?: [],
                        $coreDirs,
                    ),
                    is_dir(...),
                )),
                'fallbacks' => [LocaleEnum::default()->value],
            ],
        ]);
    }
}
