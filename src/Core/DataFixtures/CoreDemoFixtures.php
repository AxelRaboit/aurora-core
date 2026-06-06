<?php

declare(strict_types=1);

namespace Aurora\Core\DataFixtures;

use Aurora\Module\Platform\Agency\Entity\Agency;
use Aurora\Module\Platform\Agency\Entity\AgencyInterface;
use Aurora\Module\Platform\Service\Entity\Service;
use Aurora\Module\Platform\Service\Entity\ServiceInterface;
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
use function count;

/**
 * Demo scaffolding shared by every module's demo fixtures: the four demo
 * users (+ their agencies/services). Each user is exposed via a fixture
 * reference ({@see userRef}) so module fixtures — which ship in their own
 * Composer package and cannot import this concrete data — stay decoupled:
 * they only depend on this class and pull users by reference.
 *
 * Dev/test only — registered via `when@dev` in config/services.yaml.
 */
class CoreDemoFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    /** Number of demo users seeded (indices 0..USER_COUNT-1). */
    public const int USER_COUNT = 4;

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
        $this->createAgenciesAndServices($manager, $users);

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
                    // CRM — full sales access
                    'crm.contacts.view', 'crm.contacts.create', 'crm.contacts.edit', 'crm.contacts.delete',
                    'crm.companies.view', 'crm.companies.create', 'crm.companies.edit', 'crm.companies.delete',
                    'crm.deals.view', 'crm.deals.create', 'crm.deals.edit', 'crm.deals.delete',
                    // GED — full document management
                    'ged.documents.view', 'ged.documents.create', 'ged.documents.edit', 'ged.documents.delete',
                    'ged.categories.view', 'ged.categories.create', 'ged.categories.edit', 'ged.categories.delete',
                    'ged.tags.manage', 'ged.folders.manage',
                ],
                'mood' => 'Commercial senior',
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
                    'media.view', 'media.create', 'media.edit', 'media.delete',
                    'media.folders.create', 'media.folders.edit', 'media.folders.delete',
                ],
                'mood' => 'Rédactrice en chef ✍️',
            ],
            [
                'email' => 'thomas.petit@aurora.app',
                'name' => 'Thomas Petit',
                'role' => UserRoleEnum::User,
                'privileges' => [
                    'general.dashboard.view',
                    // Ecommerce — full sales access (refund stays admin-only)
                    'ecommerce.listings.view', 'ecommerce.listings.create', 'ecommerce.listings.edit', 'ecommerce.listings.delete',
                    'ecommerce.orders.view', 'ecommerce.orders.edit',
                    // Billing — full accounting
                    'billing.invoices.view', 'billing.invoices.create', 'billing.invoices.edit', 'billing.invoices.delete',
                    'billing.tiers.view', 'billing.tiers.edit', 'billing.tiers.delete',
                    'billing.ocr.import',
                    // ERP products
                    'erp.products.view', 'erp.products.create', 'erp.products.edit',
                ],
                'mood' => 'Responsable boutique & facturation',
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

    /** @param User[] $users */
    private function createAgenciesAndServices(EntityManagerInterface $em, array $users): void
    {
        $agencyDefs = [
            'Agence Nord',
            'Agence Sud',
            'Agence Est',
            'Agence Ouest',
            'Siège Social',
        ];

        $serviceDefs = [
            'Développement',
            'Commercial',
            'Ressources Humaines',
            'Direction',
            'Marketing',
        ];

        // Resolve concrete classes via Doctrine metadata so clients that
        // substitute Agency/Service through resolve_target_entities still
        // get the right class — `new Agency()` would bypass the mapping.
        /** @var class-string<Agency> $agencyClass */
        $agencyClass = $em->getClassMetadata(AgencyInterface::class)->getName();
        /** @var class-string<Service> $serviceClass */
        $serviceClass = $em->getClassMetadata(ServiceInterface::class)->getName();

        $agencies = [];
        foreach ($agencyDefs as $name) {
            $agency = new $agencyClass()->setName($name);
            $em->persist($agency);
            $agencies[] = $agency;
        }

        $services = [];
        foreach ($serviceDefs as $name) {
            $service = new $serviceClass()->setName($name);
            $em->persist($service);
            $services[] = $service;
        }

        $em->flush();

        foreach ($users as $index => $user) {
            $user->setAgency($agencies[$index % count($agencies)]);
            $user->setService($services[$index % count($services)]);
        }
    }
}
