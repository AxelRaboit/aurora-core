<?php

declare(strict_types=1);

namespace Aurora\Core\DataFixtures;

use Aurora\Core\Bootstrap\CoreBootstrapProvider;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Development accounts, and nothing else.
 *
 * This used to also create the locales, the default theme and four settings —
 * bootstrap data, which never reached production because DoctrineFixturesBundle
 * is dev/test only. Locales and theme moved to {@see CoreBootstrapProvider},
 * run by `aurora:install` in every environment; the four settings were already
 * declared in ApplicationParameterEnum and created by
 * `aurora:application-parameter`, so seeding them here was a second source of
 * truth for the same rows.
 *
 * The accounts below stay fixtures on purpose: `dev@aurora.app` / `password` is
 * a convenience for local work and must never be created anywhere else. A real
 * install gets its first user from `aurora:user:create`.
 */
class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
    ) {}

    public function load(ObjectManager $manager): void
    {
        // Admin user (backend)
        $adminUser = new User();
        $adminUser->setEmail('dev@aurora.app')
             ->setName('Admin User')
             ->setRoles([UserRoleEnum::Dev->value])
             ->setPassword($this->hasher->hashPassword($adminUser, 'password'));
        $manager->persist($adminUser);

        // Frontend user — same email, accessible via front login
        $frontUser = new User();
        $frontUser->setEmail('dev@aurora.app')
             ->setName('Admin User')
             ->setType(UserTypeEnum::Frontend)
             ->setRoles([UserRoleEnum::Dev->value])
             ->setPassword($this->hasher->hashPassword($frontUser, 'password'));
        $manager->persist($frontUser);

        $manager->flush();
    }
}
