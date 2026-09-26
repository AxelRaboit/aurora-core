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
use Aurora\Module\Editorial\Form\Message\DeliverFormSubmissionMessage;
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
use Aurora\Module\Editorial\Post\Preview\Entity\PostPreviewToken;
use Aurora\Module\Editorial\Post\Preview\Entity\PostPreviewTokenInterface;
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
use Aurora\Module\Ged\Document\Message\RelocateDocumentMessage;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategory;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategoryInterface;
use Aurora\Module\Ged\DocumentFolder\Entity\DocumentFolder;
use Aurora\Module\Ged\DocumentFolder\Entity\DocumentFolderInterface;
use Aurora\Module\Ged\DocumentTag\Entity\DocumentTag;
use Aurora\Module\Ged\DocumentTag\Entity\DocumentTagInterface;
use Aurora\Module\Notes\Folder\Entity\NoteFolder;
use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNote;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Share\Entity\MarkdownNoteShareLink;
use Aurora\Module\Notes\Share\Entity\MarkdownNoteShareLinkInterface;
use Aurora\Module\Planning\Attendee\Entity\PlanningEventAttendee;
use Aurora\Module\Planning\Attendee\Entity\PlanningEventAttendeeInterface;
use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Event\Entity\PlanningEventAlert;
use Aurora\Module\Planning\Event\Entity\PlanningEventAlertInterface;
use Aurora\Module\Planning\Event\Entity\PlanningEventInterface;
use Aurora\Module\Planning\Link\Entity\PlanningShareLink;
use Aurora\Module\Planning\Link\Entity\PlanningShareLinkInterface;
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
use Aurora\Module\Studio\Contract\Access\Entity\ContractAccessLink;
use Aurora\Module\Studio\Contract\Access\Entity\ContractAccessLinkInterface;
use Aurora\Module\Studio\Contract\Entity\Contract;
use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplate;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersion;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersionInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersionTranslation;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersionTranslationInterface;
use Aurora\Module\Studio\Contract\Signature\Entity\ContractSignature;
use Aurora\Module\Studio\Contract\Signature\Entity\ContractSignatureChallenge;
use Aurora\Module\Studio\Contract\Signature\Entity\ContractSignatureChallengeInterface;
use Aurora\Module\Studio\Contract\Signature\Entity\ContractSignatureInterface;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceMember;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceMemberInterface;
use Aurora\Module\Studio\CustomerSpace\Message\SpaceActivityDigestMessage;
use Aurora\Module\Studio\Deck\Entity\Deck;
use Aurora\Module\Studio\Deck\Entity\DeckCategory;
use Aurora\Module\Studio\Deck\Entity\DeckCategoryInterface;
use Aurora\Module\Studio\Deck\Entity\DeckInterface;
use Aurora\Module\Studio\Deck\Entity\Slide;
use Aurora\Module\Studio\Deck\Entity\SlideInterface;
use Aurora\Module\Studio\Deck\Share\Entity\DeckShareLink;
use Aurora\Module\Studio\Deck\Share\Entity\DeckShareLinkInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLink;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannel;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannelInterface;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannelMember;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannelMemberInterface;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatMessage;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatMessageInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentAttachment;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentAttachmentInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumn;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumnInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentComment;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentCommentInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItem;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItemInterface;
use Aurora\Module\Studio\SpaceFile\Entity\SpaceFile;
use Aurora\Module\Studio\SpaceFile\Entity\SpaceFileInterface;
use Aurora\Module\Studio\SpaceNote\Entity\SpaceNote;
use Aurora\Module\Studio\SpaceNote\Entity\SpaceNoteInterface;
use Aurora\Module\Studio\SpaceResource\Entity\SpaceResource;
use Aurora\Module\Studio\SpaceResource\Entity\SpaceResourceInterface;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Override;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Mercure\ProtocolVersion;

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
                    MarkdownNoteInterface::class => MarkdownNote::class,
                    NoteFolderInterface::class => NoteFolder::class,
                    MarkdownNoteShareLinkInterface::class => MarkdownNoteShareLink::class,
                    PlanningShareLinkInterface::class => PlanningShareLink::class,
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
                    PostPreviewTokenInterface::class => PostPreviewToken::class,
                    PostTranslationInterface::class => PostTranslation::class,
                    PostRevisionInterface::class => PostRevision::class,
                    PostSlugHistoryInterface::class => PostSlugHistory::class,
                    PostTypeInterface::class => PostType::class,
                    PostTypeFieldInterface::class => PostTypeField::class,
                    TaxonomyInterface::class => Taxonomy::class,
                    TaxonomyTranslationInterface::class => TaxonomyTranslation::class,
                    TaxonomyTermInterface::class => TaxonomyTerm::class,
                    TaxonomyTermTranslationInterface::class => TaxonomyTermTranslation::class,
                    CustomerInterface::class => Customer::class,
                    CustomerSpaceInterface::class => CustomerSpace::class,
                    CustomerSpaceMemberInterface::class => CustomerSpaceMember::class,
                    SpaceContentColumnInterface::class => SpaceContentColumn::class,
                    SpaceContentItemInterface::class => SpaceContentItem::class,
                    SpaceContentAttachmentInterface::class => SpaceContentAttachment::class,
                    SpaceContentCommentInterface::class => SpaceContentComment::class,
                    SpaceAccessLinkInterface::class => SpaceAccessLink::class,
                    SpaceChatMessageInterface::class => SpaceChatMessage::class,
                    SpaceChatChannelInterface::class => SpaceChatChannel::class,
                    SpaceChatChannelMemberInterface::class => SpaceChatChannelMember::class,
                    SpaceNoteInterface::class => SpaceNote::class,
                    SpaceFileInterface::class => SpaceFile::class,
                    SpaceResourceInterface::class => SpaceResource::class,
                    DeckInterface::class => Deck::class,
                    DeckCategoryInterface::class => DeckCategory::class,
                    SlideInterface::class => Slide::class,
                    DeckShareLinkInterface::class => DeckShareLink::class,
                    ContractInterface::class => Contract::class,
                    ContractAccessLinkInterface::class => ContractAccessLink::class,
                    ContractSignatureInterface::class => ContractSignature::class,
                    ContractSignatureChallengeInterface::class => ContractSignatureChallenge::class,
                    ContractTemplateInterface::class => ContractTemplate::class,
                    ContractTemplateVersionInterface::class => ContractTemplateVersion::class,
                    ContractTemplateVersionTranslationInterface::class => ContractTemplateVersionTranslation::class,
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

        // **The live hub, and the fact that most installations will not have
        // one.** A space's chat is built on stored messages and a plain POST;
        // the hub only makes them arrive without a refresh. So every variable
        // below defaults to empty, the bundle is configured all the same, and
        // `SpaceChatHub` treats an empty URL as "no hub" - it publishes nothing
        // and hands the pages no address to connect to. Setting MERCURE_URL is
        // the whole of switching it on, which is why there is no separate flag
        // to get out of step with it.
        //
        // **Protocol 1.0, not the component's default.** The component still
        // defaults to the pre-1.0 protocol; a 1.0 hub rejects those tokens
        // outright, which is measured rather than assumed. The tokens are
        // therefore RFC 9068 access tokens, and the two services below carry
        // the claims and the publish grant that a yaml node cannot express.
        $builder->setParameter('env(MERCURE_URL)', '');
        $builder->setParameter('env(MERCURE_PUBLIC_URL)', '');
        $builder->setParameter('env(MERCURE_JWT_SECRET)', '');
        $builder->setParameter('env(MERCURE_ISSUER)', '');

        $builder->prependExtensionConfig('mercure', [
            'hubs' => [
                'default' => [
                    'url' => '%env(MERCURE_URL)%',
                    // What the browser is told to connect to, which is not what
                    // PHP posts to: the hub is reached over the loopback from
                    // the server and through the public host from a client's
                    // machine. A 1.0 hub works out its own audience from the
                    // request, so the two only agree if the hub pins its
                    // `resource_identifier` to this same address.
                    'public_url' => '%env(MERCURE_PUBLIC_URL)%',
                    'protocol_version' => ProtocolVersion::V1->value,
                    'jwt' => [
                        // Publishing, with the grant the pattern needs; and the
                        // factory `Authorization` reaches for when it mints a
                        // subscriber's cookie. Both are declared in
                        // config/services.yaml, where the reason they exist is
                        // written out.
                        'provider' => 'aurora.mercure.publisher',
                        'factory' => 'aurora.mercure.token_factory',
                    ],
                ],
            ],
        ]);

        $builder->prependExtensionConfig('doctrine_migrations', [
            'migrations_paths' => [
                'DoctrineMigrations' => $dir.'/migrations',
            ],
            'enable_profiler' => false,
        ]);

        // Aurora's own messages are routed by Aurora. A client only provides
        // the `async` transport, which its messenger.yaml already documents as
        // required.
        //
        // It used to be the client's job to repeat this list, and nothing said
        // so: a message left unrouted is not an error, Symfony simply handles
        // it inline. So the queue silently did not exist on consumer projects
        // - the GED relocation ran inside the request that asked for it, and a
        // form submission would have gone on mailing from the visitor's own.
        // Same shape as the rate limiters, which do fail loudly; these did not.
        $builder->prependExtensionConfig('framework', [
            'messenger' => [
                'routing' => [
                    RelocateDocumentMessage::class => 'async',
                    DeliverFormSubmissionMessage::class => 'async',
                    // The only one of the three that is routed for its delay
                    // rather than its cost: it is dispatched with a five minute
                    // stamp, and handled inline it would be sent immediately -
                    // which is precisely the mail it exists to avoid.
                    SpaceActivityDigestMessage::class => 'async',
                ],
            ],
        ]);

        /*
         * Les neuf limiteurs que les contrôleurs d'aurora-core câblent par leur
         * nom.
         *
         * La liste se relit par `grep -oE '\$[a-zA-Z]+Limiter' src/` : en
         * oublier un ne se voit qu'au déploiement d'un projet client, sur le
         * contrôleur qui le demande.
         *
         * **C'était au client de les répéter, et rien ne le disait** - sinon un
         * conteneur qui refuse de se construire au premier déploiement, sur un
         * service dont le projet n'a jamais entendu parler. Le commentaire du
         * routage messenger juste au-dessus notait déjà que les limiteurs ont
         * cette forme ; il a fallu en ajouter un pour que ça se voie.
         *
         * Fournis ici, ils arrivent avec le paquet. Un client qui veut d'autres
         * chiffres redéclare la clé dans son propre `rate_limiter.yaml` : sa
         * configuration est chargée après, donc elle gagne.
         */
        $builder->prependExtensionConfig('framework', [
            'rate_limiter' => [
                // L'envoi d'un formulaire du site public.
                'form_submission' => ['policy' => 'sliding_window', 'limit' => 10, 'interval' => '1 hour'],
                // La signature d'un contrat par quelqu'un qui tient un lien.
                'contract_signature' => ['policy' => 'sliding_window', 'limit' => 10, 'interval' => '1 hour'],
                'contract_signature_code' => ['policy' => 'sliding_window', 'limit' => 15, 'interval' => '1 hour'],
                // Le mot de passe d'un lien de présentation.
                'deck_share_password' => ['policy' => 'sliding_window', 'limit' => 20, 'interval' => '1 hour'],
                // Les gestes d'un client sur l'espace qu'un lien lui ouvre :
                // valider, commenter, écrire. Plus haut que la signature parce
                // qu'on parcourt un mois et qu'on valide six publications
                // d'affilée, là où on ne signe qu'une fois.
                'space_guest_write' => ['policy' => 'sliding_window', 'limit' => 40, 'interval' => '1 hour'],
                // Le dépôt d'un fichier : il traverse le stockage, la vignette
                // et, pour une vidéo, la capture d'une image de couverture.
                'space_guest_upload' => ['policy' => 'sliding_window', 'limit' => 20, 'interval' => '1 hour'],
                // Le lot du dossier Drive, la route publique la plus chère :
                // chaque fichier est téléchargé chez Google et le zip est
                // construit en entier avant le premier octet envoyé.
                'space_guest_archive' => ['policy' => 'sliding_window', 'limit' => 5, 'interval' => '1 hour'],
                // L'inscription à la lettre d'information et la prise d'un
                // rendez-vous, sur le même mur extérieur que les autres,
                // gardées par IP.
                'newsletter_subscription' => ['policy' => 'sliding_window', 'limit' => 10, 'interval' => '1 hour'],
                'editorial_booking' => ['policy' => 'sliding_window', 'limit' => 10, 'interval' => '1 hour'],
            ],
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
