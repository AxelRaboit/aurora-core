<?php

declare(strict_types=1);

namespace Aurora\Core\DataFixtures;

use Aurora\Core\Locale\Entity\Locale;
use Aurora\Core\Locale\Enum\LocaleEnum;
use Aurora\Module\Configuration\Setting\Entity\Setting;
use Aurora\Module\Configuration\Theme\Entity\Theme;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Core bootstrap fixtures — only entities that live in the always-on core
 * (Locale, Configuration's Setting/Theme, Platform's User). Module-specific
 * seed data (editorial post types, taxonomies, sample pages, …) now lives in
 * each module package's own DataFixtures so the core stays decoupled and a
 * client without a given module never references its entities.
 */
class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
    ) {}

    public function load(ObjectManager $manager): void
    {
        // Locales
        $frenchLocale = new Locale()->setCode(LocaleEnum::French->value)->setName('Français')->setIsDefault(true)->setPosition(0);
        $englishLocale = new Locale()->setCode(LocaleEnum::English->value)->setName('English')->setPosition(1);
        $manager->persist($frenchLocale);
        $manager->persist($englishLocale);

        // Default theme
        $theme = new Theme()
            ->setSlug('default')
            ->setName('Default')
            ->setDescription('Thème par défaut de Aurora')
            ->setActive(true);
        $manager->persist($theme);

        // Settings
        $settings = [
            ['site_name', 'Aurora', 'string', 'general'],
            ['site_description', 'Propulsé par Aurora', 'string', 'general'],
            ['default_locale', LocaleEnum::French->value, 'string', 'general'],
            ['posts_per_page', '10', 'int', 'reading'],
        ];

        foreach ($settings as [$key, $value, $type, $group]) {
            $setting = new Setting()->setKey($key)->setValue($value)->setType($type)->setGroup($group);
            $manager->persist($setting);
        }

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
