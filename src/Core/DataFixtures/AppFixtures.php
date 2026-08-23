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
 * This used to also create the locales, the default theme and four settings -
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
    private const string EMAIL = 'dev@aurora.app';

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
    ) {}

    public function load(ObjectManager $manager): void
    {
        // One account per side. Same address on purpose - the backend and the
        // frontend are two logins, and the unique key is the pair.
        $this->account($manager, UserTypeEnum::Backend);
        $this->account($manager, UserTypeEnum::Frontend);

        $manager->flush();
    }

    /**
     * Created once and left alone afterwards.
     *
     * It used to always insert, so a second `doctrine:fixtures:load --append`
     * died on the unique (email, type) - and `make demo` runs exactly that,
     * after purging var/uploads. A reload that half-runs is worse than one
     * that refuses: the pictures were gone and the rows were not replaced.
     */
    private function account(ObjectManager $manager, UserTypeEnum $type): void
    {
        $existing = $manager->getRepository(User::class)
            ->findOneBy(['email' => self::EMAIL, 'type' => $type]);

        if ($existing instanceof User) {
            return;
        }

        $user = new User();
        $user->setEmail(self::EMAIL)
             ->setName('Admin User')
             ->setType($type)
             ->setRoles([UserRoleEnum::Dev->value])
             ->setPassword($this->hasher->hashPassword($user, 'password'));

        $manager->persist($user);
    }
}
