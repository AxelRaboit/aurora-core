<?php

declare(strict_types=1);

namespace Aurora\Fixtures\Core;

use Aurora\Core\Locale\Enum\LocaleEnum;
use Aurora\Core\Notification\Entity\Notification;
use Aurora\Module\Configuration\Theme\Entity\Theme;
use Aurora\Module\Platform\Auth\Entity\AccessRequest;
use Aurora\Module\Platform\Auth\Enum\AccessRequestStatusEnum;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

use function assert;

/**
 * Demo scaffolding shared by every module's demo fixtures: the demo users.
 * Each user is exposed via a fixture reference ({@see userRef}) so module
 * fixtures - which ship in their own Composer package and cannot import
 * this concrete data - stay decoupled: they only depend on this class and
 * pull users by reference.
 *
 * Dev/test only - registered via `when@dev` in config/services.yaml.
 */
class CoreDemoFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    /** Number of demo users seeded (indices 0..USER_COUNT-1). */
    public const int USER_COUNT = 2;

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
    ) {}

    /** Reference name for the demo user at the given index. */
    public static function userRef(int $index): string
    {
        return 'core_demo_user_'.$index;
    }

    public static function getGroups(): array
    {
        return ['demo'];
    }

    public function getDependencies(): array
    {
        return [AppFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        assert($manager instanceof EntityManagerInterface);

        $users = $this->createUsers($manager);

        foreach ($users as $i => $user) {
            $this->addReference(self::userRef($i), $user);
        }

        $this->createThemes($manager);

        $manager->flush();

        $this->createNotifications($manager);
        $this->createAccessRequests($manager);
    }

    /**
     * Quelques notifications dans la cloche.
     *
     * Elles naissent d'habitude d'une tâche de fond : un rappel d'événement
     * qui arrive à échéance, une publication envoyée en relecture. Une démo
     * fraîche n'en a donc aucune tant que le worker n'a pas tourné, et la
     * cloche s'ouvre sur « Aucune notification » - ce qu'a montré la page de
     * documentation qui la décrit.
     *
     * Écrites directement plutôt qu'attendues : ce qu'il faut voir est ce
     * que la cloche affiche, et trois lignes dont une lue le disent mieux
     * qu'un délai à espérer.
     *
     * Idempotent sur le titre, par destinataire.
     */
    private function createNotifications(EntityManagerInterface $em): void
    {
        // Le compte que l'on regarde, pas le premier de la liste. Les
        // notifications sont personnelles : rangées ailleurs, la cloche
        // s'ouvre vide pour qui prend la capture, ce qui est exactement ce
        // qui s'est passé la première fois.
        $recipient = $em->getRepository(User::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => UserTypeEnum::Backend->value]);

        if (!$recipient instanceof User) {
            return;
        }

        $repository = $em->getRepository(Notification::class);
        $now = new DateTimeImmutable();

        $entries = [
            [
                'type' => 'editorial.review',
                'title' => 'Une publication attend votre relecture',
                'body' => '« Relire avant de publier » a été envoyée en relecture par Marie Dupont.',
                'url' => '/backend/editorial/posts',
                'at' => $now->modify('-2 hours'),
                'read' => false,
            ],
            [
                'type' => 'editorial.comment',
                'title' => 'Un commentaire attend la modération',
                'body' => 'Sofia Marchetti a commenté « Écrire son premier article ».',
                'url' => '/backend/editorial/comments',
                'at' => $now->modify('-1 day'),
                'read' => false,
            ],
            [
                'type' => 'planning.reminder',
                'title' => 'Point hebdomadaire dans une heure',
                'body' => 'Pro, aujourd\'hui à 11:00.',
                'url' => '/backend/planning/calendar',
                'at' => $now->modify('-3 days'),
                'read' => true,
            ],
        ];

        foreach ($entries as $entry) {
            $existing = $repository->findOneBy(['recipient' => $recipient, 'title' => $entry['title']]);
            $notification = $existing ?? new Notification();

            $notification
                ->setRecipient($recipient)
                ->setType($entry['type'])
                ->setTitle($entry['title'])
                ->setBody($entry['body'])
                ->setUrl($entry['url']);

            // L'entité ne laisse pas écrire la date de lecture : elle se
            // pose en marquant lu, ce qui est la seule façon dont ça arrive
            // dans le produit.
            if ($entry['read']) {
                $notification->markAsRead();
            }

            $em->persist($notification);
        }

        $em->flush();
    }

    /**
     * Palettes to switch between while showing the site.
     *
     * The install seeds one theme, which is enough to prove the screen exists
     * and not enough to show what it does: a list of one has nothing to
     * compare. These three are complete looks - ground, bands and accent
     * chosen together - so switching one radio button visibly changes the
     * public site, which is the whole point of the feature.
     *
     * Inactive on purpose. A demo reload must not take the site away from
     * whatever theme somebody is currently working on.
     */
    /**
     * Trois demandes d'accès, une par état.
     *
     * Elles naissent d'un formulaire public que personne ne remplit sur une
     * démo, donc l'écran qui les traite s'ouvrait sur « Aucune demande
     * d'accès » - et c'est cet écran vide qui est parti dans la
     * documentation. Une en attente pour montrer les actions, une acceptée
     * et une refusée pour montrer ce que la liste garde.
     *
     * Idempotent sur l'adresse du demandeur.
     */
    private function createAccessRequests(EntityManagerInterface $em): void
    {
        $repository = $em->getRepository(AccessRequest::class);
        $now = new DateTimeImmutable();

        $defs = [
            [
                'email' => 'camille.perrot@atelier-dupont.test',
                'name' => 'Camille Perrot',
                'message' => "Bonjour, je reprends la rédaction du blog à partir d'octobre. Pouvez-vous m'ouvrir un accès ?",
                'status' => AccessRequestStatusEnum::Pending,
                'expires' => '+5 days',
            ],
            [
                'email' => 'sofiane.benali@atelier-dupont.test',
                'name' => 'Sofiane Benali',
                'message' => 'Besoin de déposer les visuels du salon dans la médiathèque.',
                'status' => AccessRequestStatusEnum::Approved,
                'expires' => '-2 days',
            ],
            [
                'email' => 'contact@referencement-express.test',
                'name' => 'Agence Référencement Express',
                'message' => "Nous proposons un audit SEO gratuit. Merci de nous ouvrir un accès pour l'installer.",
                'status' => AccessRequestStatusEnum::Rejected,
                'expires' => '-9 days',
            ],
        ];

        foreach ($defs as $def) {
            if (null !== $repository->findOneBy(['requesterEmail' => $def['email']])) {
                continue;
            }

            $request = new AccessRequest($def['email'], $now->modify($def['expires']));
            $request->setRequesterName($def['name'])
                ->setMessage($def['message'])
                ->setStatus($def['status']);

            $em->persist($request);
        }

        $em->flush();
    }

    private function createThemes(EntityManagerInterface $em): void
    {
        $defs = [
            [
                'slug' => 'nuit-emeraude',
                'name' => 'Nuit émeraude',
                'description' => 'Fond encre, accent vert. Lisible longtemps, sobre en photo.',
                'config' => [
                    'primary_color' => '#10b981',
                    'background_color' => '#030712',
                    'header_color' => '#111827',
                    'footer_color' => '#111827',
                ],
            ],
            [
                'slug' => 'papier',
                'name' => 'Papier',
                'description' => 'Fond clair et accent ardoise, pour un site qui se lit comme un document.',
                'config' => [
                    'primary_color' => '#1f2937',
                    'background_color' => '#faf9f6',
                    'header_color' => '#ffffff',
                    'footer_color' => '#f3f4f6',
                ],
            ],
            [
                'slug' => 'corail',
                'name' => 'Corail',
                'description' => 'Chaud et affirmé : bandeaux profonds, accent orangé sur les liens.',
                'config' => [
                    'primary_color' => '#f97316',
                    'background_color' => '#1c1917',
                    'header_color' => '#292524',
                    'footer_color' => '#292524',
                ],
            ],
        ];

        $repository = $em->getRepository(Theme::class);

        foreach ($defs as $def) {
            // Reused by slug, like the users above: `make demo` runs twice.
            $theme = $repository->findOneBy(['slug' => $def['slug']]) ?? new Theme();

            $theme->setSlug($def['slug'])
                ->setName($def['name'])
                ->setDescription($def['description'])
                ->setConfig($def['config']);

            if (null === $theme->getId()) {
                $theme->setActive(false);
                $em->persist($theme);
            }
        }

        $em->flush();

        // The install seeds `default` with a name and no palette, so a demo
        // opened straight after `make demo` served the stylesheet's own
        // fallbacks: correct, and undecided. The first of these is switched on
        // in that case only - a site whose default theme carries colours has
        // been dressed by somebody, and a fixture reload must not undress it.
        $active = $repository->findOneBy(['active' => true]);

        if (null === $active || ('default' === $active->getSlug() && [] === $active->getConfig())) {
            $chosen = $repository->findOneBy(['slug' => 'nuit-emeraude']);

            if (null !== $chosen) {
                if (null !== $active) {
                    $active->setActive(false);
                }

                $chosen->setActive(true);
            }
        }
    }

    /** @return User[] */
    private function createUsers(EntityManagerInterface $em): array
    {
        $users = [];

        $defs = [
            [
                'email' => 'marie.dupont@aurora.app',
                'name' => 'Marie Dupont',
                'role' => UserRoleEnum::Admin,
                'privileges' => [],
                'mood' => 'Responsable des opérations 🚀',
            ],
            [
                'email' => 'jean.martin@aurora.app',
                'name' => 'Jean Martin',
                'role' => UserRoleEnum::User,
                'privileges' => [
                    'general.dashboard.view',
                    // GED - full document management
                    'ged.documents.view', 'ged.documents.create', 'ged.documents.edit', 'ged.documents.delete',
                    'ged.categories.view', 'ged.categories.create', 'ged.categories.edit', 'ged.categories.delete',
                    'ged.tags.manage', 'ged.folders.manage',
                    // Les espaces clients, pour que la démonstration porte le
                    // cas que le modèle existe pour montrer : un équipier qui
                    // a le droit de travailler dans un espace, et qui ne voit
                    // que ceux dont il est membre. Sans lui, la liste
                    // paraîtrait toujours complète à qui la regarde.
                    'studio.spaces.view', 'studio.spaces.edit', 'studio.spaces.share',
                ],
                'mood' => 'Gestionnaire documentaire',
            ],
        ];

        $repository = $em->getRepository(User::class);

        foreach ($defs as $def) {
            // Reused when it is already there, so `make demo` can be run twice.
            // It used to always insert, and the second run died on the unique
            // (email, type) - after purging var/uploads, which is the first
            // thing that target does. A reload that half-runs is worse than one
            // that refuses.
            $user = $repository->findOneBy(['email' => $def['email']]) ?? new User();
            $fresh = null === $user->getId();

            $user->setEmail($def['email'])
                 ->setName($def['name'])
                 ->setRoles([$def['role']->value])
                 ->setPrivileges($def['privileges'])
                 ->setMoodMessage($def['mood'])
                 ->setLocale(LocaleEnum::French);

            // Only on creation: a reload refreshes what the demo describes -
            // the name, the rights - without resetting a password somebody
            // changed in the meantime.
            if ($fresh) {
                $user->setPassword($this->hasher->hashPassword($user, 'password'));
                $em->persist($user);
            }

            $users[] = $user;
        }

        return $users;
    }
}
