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
use Aurora\Module\Editorial\Comment\Entity\Comment;
use Aurora\Module\Editorial\Comment\Entity\CommentInterface;
use Aurora\Module\Editorial\Comment\Entity\CommentReaction;
use Aurora\Module\Editorial\Comment\Entity\CommentReactionInterface;
use Aurora\Module\Editorial\Form\Entity\Form;
use Aurora\Module\Editorial\Form\Entity\FormField;
use Aurora\Module\Editorial\Form\Entity\FormFieldInterface;
use Aurora\Module\Editorial\Form\Entity\FormFieldTranslation;
use Aurora\Module\Editorial\Form\Entity\FormFieldTranslationInterface;
use Aurora\Module\Editorial\Form\Entity\FormInterface;
use Aurora\Module\Editorial\Form\Entity\FormSubmission;
use Aurora\Module\Editorial\Form\Entity\FormSubmissionInterface;
use Aurora\Module\Editorial\Form\Entity\FormTranslation;
use Aurora\Module\Editorial\Form\Entity\FormTranslationInterface;
use Aurora\Module\Editorial\Menu\Entity\Menu;
use Aurora\Module\Editorial\Menu\Entity\MenuInterface;
use Aurora\Module\Editorial\Menu\Entity\MenuItem;
use Aurora\Module\Editorial\Menu\Entity\MenuItemInterface;
use Aurora\Module\Editorial\Menu\Entity\MenuItemTranslation;
use Aurora\Module\Editorial\Menu\Entity\MenuItemTranslationInterface;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Entity\PostRevision;
use Aurora\Module\Editorial\Post\Entity\PostRevisionInterface;
use Aurora\Module\Editorial\Post\Entity\PostSlugHistory;
use Aurora\Module\Editorial\Post\Entity\PostSlugHistoryInterface;
use Aurora\Module\Editorial\Post\Entity\PostTranslation;
use Aurora\Module\Editorial\Post\Entity\PostTranslationInterface;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Module\Editorial\PostType\Entity\PostTypeField;
use Aurora\Module\Editorial\PostType\Entity\PostTypeFieldInterface;
use Aurora\Module\Editorial\PostType\Entity\PostTypeInterface;
use Aurora\Module\Editorial\Taxonomy\Entity\Taxonomy;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyInterface;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTerm;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTermInterface;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTermTranslation;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTermTranslationInterface;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTranslation;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTranslationInterface;
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
use Aurora\Module\Planning\Attendee\Entity\PlanningEventAttendee;
use Aurora\Module\Planning\Attendee\Entity\PlanningEventAttendeeInterface;
use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Event\Entity\PlanningEventAlert;
use Aurora\Module\Planning\Event\Entity\PlanningEventAlertInterface;
use Aurora\Module\Planning\Event\Entity\PlanningEventInterface;
use Aurora\Module\Planning\Planning\Entity\Planning;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use Aurora\Module\Planning\Reminder\Entity\PlanningReminder;
use Aurora\Module\Planning\Reminder\Entity\PlanningReminderInterface;
use Aurora\Module\Planning\Share\Entity\PlanningShare;
use Aurora\Module\Planning\Share\Entity\PlanningShareInterface;
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
     * Override AbstractBundle::getPath() - the default returns
     * `dirname(file, 2)` which resolves to the project root (or vendor
     * package root when used by a client). That makes Symfony's
     * `assets:install` treat the project's `public/` as the bundle's
     * `Resources/public` and copy it recursively into
     * `public/bundles/aurora/` - infinite nesting.
     *
     * Returning `__DIR__` (the `src/` dir) scopes the bundle to its
     * code dir; no `src/public/` exists, so no asset copy happens.
     * All internal paths in this bundle use `dirname(__DIR__)` directly,
     * so the override doesn't affect translations / Doctrine mappings /
     * Twig namespaces - they still resolve against the project root.
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

        // Only this monorepo's own modules. A module shipped as a separate
        // Composer package registers its Doctrine mapping / Twig / i18n /
        // resolve_target_entities from its own Aurora<Name>Bundle instead.
        $moduleDirs = glob($dir.'/src/Module/*', GLOB_ONLYDIR) ?: [];

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
                    PlanningInterface::class => Planning::class,
                    PlanningEventInterface::class => PlanningEvent::class,
                    PlanningEventAlertInterface::class => PlanningEventAlert::class,
                    PlanningReminderInterface::class => PlanningReminder::class,
                    PlanningEventAttendeeInterface::class => PlanningEventAttendee::class,
                    PlanningShareInterface::class => PlanningShare::class,
                    CommentInterface::class => Comment::class,
                    CommentReactionInterface::class => CommentReaction::class,
                    FormInterface::class => Form::class,
                    FormTranslationInterface::class => FormTranslation::class,
                    FormFieldInterface::class => FormField::class,
                    FormFieldTranslationInterface::class => FormFieldTranslation::class,
                    FormSubmissionInterface::class => FormSubmission::class,
                    MenuInterface::class => Menu::class,
                    MenuItemInterface::class => MenuItem::class,
                    MenuItemTranslationInterface::class => MenuItemTranslation::class,
                    PostInterface::class => Post::class,
                    PostTranslationInterface::class => PostTranslation::class,
                    PostRevisionInterface::class => PostRevision::class,
                    PostSlugHistoryInterface::class => PostSlugHistory::class,
                    PostTypeInterface::class => PostType::class,
                    PostTypeFieldInterface::class => PostTypeField::class,
                    TaxonomyInterface::class => Taxonomy::class,
                    TaxonomyTranslationInterface::class => TaxonomyTranslation::class,
                    TaxonomyTermInterface::class => TaxonomyTerm::class,
                    TaxonomyTermTranslationInterface::class => TaxonomyTermTranslation::class,
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
        // for each namespace - the new co-located path (mirroring core's layout
        // since templates were moved under src/) AND the legacy top-level path
        // (kept for backward compat with existing client projects).
        $projectDir = (string) $builder->getParameter('kernel.project_dir');

        $twigPaths = [];

        // 1. Client-side overrides (highest priority - registered first).
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

        // 1bis. Modules the client owns outright. The loop above only covers
        // names aurora ships, so a module that exists solely in the client
        // project had no namespace at all and its templates were unreachable -
        // it had to fall back to the project's default templates/ directory,
        // breaking the co-location the convention asks for everywhere else.
        if ($projectDir !== $dir) {
            foreach (glob($projectDir.'/src/Module/*', GLOB_ONLYDIR) ?: [] as $clientModuleDir) {
                $moduleName = basename($clientModuleDir);
                $templates = $clientModuleDir.'/templates';

                // Aurora-owned names are handled above, with their fallback to
                // the bundle's own templates; re-registering here would shadow
                // that ordering.
                if (is_dir($dir.'/src/Module/'.$moduleName)) {
                    continue;
                }

                if (is_dir($templates)) {
                    $twigPaths[$templates] = $moduleName;
                }
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

        // 2. Bundle defaults (lowest priority - registered last).
        // Null namespace covers both the bundle's src/Core/templates/ (so
        // relative refs like 'Frontend/themes/default/...' still resolve) and
        // the legacy <bundle>/templates/ (still hosts templates/bundles/TwigBundle/
        // for Symfony's third-party override convention).
        $twigPaths[$dir.'/src/Core/templates'] = null;
        $twigPaths[$dir.'/templates'] = null;
        $twigPaths[$dir.'/src/Core/assets/css'] = 'styles';

        // The bundle's error pages, for projects that ship none of their own.
        //
        // `<bundle>/templates/bundles/TwigBundle/` is the convention for an
        // *application* overriding a bundle, so Symfony only honours it when
        // this package is the application - which it is when developing
        // aurora-core, and never in a client project. The error pages therefore
        // worked everywhere we looked at them and nowhere they were needed: a
        // 404 in production fell back to Symfony's bare "Oops!" page.
        //
        // Registering the namespace ourselves is enough, but only when the
        // project has no `templates/bundles/TwigBundle/` of its own. Twig
        // resolves a namespace by first matching path, and user-configured
        // paths are registered before per-bundle override paths - so doing this
        // unconditionally would make the bundle's pages win over the client's,
        // which is precisely backwards.
        if (!is_dir($projectDir.'/templates/bundles/TwigBundle')) {
            $twigPaths[$dir.'/templates/bundles/TwigBundle'] = 'Twig';
        }

        // Stable alias for the bundle's own theme files, so a module package
        // that shadows one via its templates/_theme/ dir can still extend the
        // original: `{% extends 'Frontend/themes/default/layout.html.twig' %}`
        // from inside such an override resolves back to the override itself and
        // recurses forever. @see AbstractAuroraModuleBundle::prepend()
        $twigPaths[$dir.'/src/Core/templates/Frontend/themes'] = 'AuroraTheme';
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

        // A client module carries its own catalogue, co-located like aurora's
        // own do. Without this every path below resolved inside the bundle, so
        // a client had exactly one place to put translations - the project's
        // root catalogue - however many modules it owned. Depth 1 and 2, to
        // match `src/Module/<Domain>/<Feature>/`.
        $clientTranslationDirs = $projectDir === $dir ? [] : array_merge(
            glob($projectDir.'/src/Module/*/translations', GLOB_ONLYDIR) ?: [],
            glob($projectDir.'/src/Module/*/*/translations', GLOB_ONLYDIR) ?: [],
        );

        $builder->prependExtensionConfig('framework', [
            'default_locale' => LocaleEnum::default()->value,
            'enabled_locales' => LocaleEnum::values(),
            'translator' => [
                'default_path' => $dir.'/src/Core/translations',
                // Client catalogues come LAST on purpose: a later path wins on
                // a shared key, so trailing position is what lets a client
                // restate an aurora string - the priority client templates
                // already get. Listed first, they were loaded and immediately
                // overwritten by the bundle's own. Verified on a real project:
                // a client entry for `backend.ged.categories.name` has no
                // effect from the front of the list and takes over from the
                // back.
                //
                // One exception, and it is Symfony's, not ours: `default_path`
                // outranks every entry in `paths`. It points at
                // src/Core/translations, so the `shared.*` catalogue there
                // cannot be overridden this way whatever the ordering.
                'paths' => array_values(array_filter(
                    array_merge(
                        array_map(static fn (string $moduleDir): string => $moduleDir.'/translations', $moduleDirs),
                        glob($dir.'/src/Module/*/*/translations', GLOB_ONLYDIR) ?: [],
                        $coreDirs,
                        $clientTranslationDirs,
                    ),
                    is_dir(...),
                )),
                'fallbacks' => [LocaleEnum::default()->value],
            ],
        ]);
    }
}
