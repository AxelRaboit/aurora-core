<?php

declare(strict_types=1);

namespace Aurora\Core\DataFixtures;

use Aurora\Core\Locale\Enum\LocaleEnum;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
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
 * fixtures — which ship in their own Composer package and cannot import
 * this concrete data — stay decoupled: they only depend on this class and
 * pull users by reference.
 *
 * Dev/test only — registered via `when@dev` in config/services.yaml.
 */
class CoreDemoFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    /** Number of demo users seeded (indices 0..USER_COUNT-1). */
    public const int USER_COUNT = 3;

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

        $manager->flush();
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
                    // GED — full document management
                    'ged.documents.view', 'ged.documents.create', 'ged.documents.edit', 'ged.documents.delete',
                    'ged.categories.view', 'ged.categories.create', 'ged.categories.edit', 'ged.categories.delete',
                    'ged.tags.manage', 'ged.folders.manage',
                ],
                'mood' => 'Gestionnaire documentaire',
            ],
            [
                'email' => 'sophie.bernard@aurora.app',
                'name' => 'Sophie Bernard',
                'role' => UserRoleEnum::User,
                'privileges' => [
                    'general.dashboard.view',
                    // Editorial — full editorial workflow
                    'editorial.posts.view', 'editorial.posts.create', 'editorial.posts.edit', 'editorial.posts.delete',
                    'editorial.menus.view', 'editorial.menus.create', 'editorial.menus.edit', 'editorial.menus.delete',
                    'editorial.taxonomies.view', 'editorial.taxonomies.create', 'editorial.taxonomies.edit',
                    'editorial.post_types.view',
                    'editorial.comments.view', 'editorial.comments.moderate', 'editorial.comments.delete',
                    'editorial.forms.view', 'editorial.forms.create', 'editorial.forms.edit', 'editorial.forms.delete',
                    'editorial.sitemap.view', 'editorial.sitemap.regenerate',
                    // Media library (editors need full CRUD on items + folders)
                    'ged.documents.view', 'ged.documents.create', 'ged.documents.edit', 'ged.documents.delete',
                    'ged.folders.manage',
                ],
                'mood' => 'Rédactrice en chef ✍️',
            ],
        ];

        foreach ($defs as $def) {
            $user = new User();
            $user->setEmail($def['email'])
                 ->setName($def['name'])
                 ->setRoles([$def['role']->value])
                 ->setPrivileges($def['privileges'])
                 ->setMoodMessage($def['mood'])
                 ->setLocale(LocaleEnum::French)
                 ->setPassword($this->hasher->hashPassword($user, 'password'));
            $em->persist($user);
            $users[] = $user;
        }

        return $users;
    }
}
