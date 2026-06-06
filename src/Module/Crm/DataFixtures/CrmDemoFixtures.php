<?php

declare(strict_types=1);

namespace Aurora\Module\Crm\DataFixtures;

use Aurora\Core\DataFixtures\CoreDemoFixtures;
use Aurora\Module\Crm\Company\Entity\Company;
use Aurora\Module\Crm\Contact\Entity\Contact;
use Aurora\Module\Crm\Contact\Enum\ContactSourceEnum;
use Aurora\Module\Crm\ContactTag\Entity\ContactTag;
use Aurora\Module\Crm\Deal\Entity\Deal;
use Aurora\Module\Crm\Deal\Enum\DealStageEnum;
use Aurora\Module\Dev\Audit\Entity\AbstractAuditLog;
use Aurora\Module\Dev\Audit\Entity\AuditLog;
use Aurora\Module\Platform\User\Entity\User;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use ReflectionProperty;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Demo CRM: companies, contacts (+ tags, deals) and a contact activity trail.
 * Companies/contacts are exposed via references for the photo & project demos.
 * Dev/test only.
 */
class CrmDemoFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    public static function companyRef(int $index): string
    {
        return 'crm_demo_company_'.$index;
    }

    public static function contactRef(int $index): string
    {
        return 'crm_demo_contact_'.$index;
    }

    public static function getGroups(): array
    {
        return ['demo'];
    }

    public function getDependencies(): array
    {
        return [CoreDemoFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        assert($manager instanceof EntityManagerInterface);

        $users = [];
        for ($i = 0; $i < CoreDemoFixtures::USER_COUNT; ++$i) {
            $users[] = $this->getReference(CoreDemoFixtures::userRef($i), User::class);
        }

        [$companies, $contacts] = $this->createCrm($manager, $users);

        foreach ($companies as $i => $company) {
            $this->addReference(self::companyRef($i), $company);
        }

        foreach ($contacts as $i => $contact) {
            $this->addReference(self::contactRef($i), $contact);
        }

        $manager->flush();
    }

    private function createCrm(EntityManagerInterface $em, array $users): array
    {
        $companies = [];
        $companyDefs = [
            ['name' => 'Tech Innovation SARL',     'industry' => 'Informatique & Logiciels',  'website' => 'https://tech-innovation.fr',    'phone' => '+33 1 42 00 11 22', 'address' => '15 rue de la Paix, 75001 Paris'],
            ['name' => 'BioMed France',             'industry' => 'Santé & Biotechnologies',   'website' => 'https://biomed-france.com',     'phone' => '+33 4 91 55 66 77', 'address' => '8 avenue du Prado, 13008 Marseille'],
            ['name' => 'Retail Connect SAS',        'industry' => 'Commerce & Distribution',   'website' => 'https://retail-connect.fr',     'phone' => '+33 4 72 11 33 55', 'address' => '42 cours Gambetta, 69007 Lyon'],
            ['name' => 'Nexus Digital Agency',      'industry' => 'Marketing & Communication', 'website' => 'https://nexus-digital.fr',      'phone' => '+33 1 55 35 00 10', 'address' => '22 rue de Rivoli, 75004 Paris'],
            ['name' => 'Groupe Leclerc Nord',       'industry' => 'Grande Distribution',       'website' => 'https://leclerc-nord.fr',       'phone' => '+33 3 20 44 55 66', 'address' => '1 rue du Commerce, 59000 Lille'],
            ['name' => 'Clinique Saint-Joseph',     'industry' => 'Santé',                     'website' => 'https://clinique-sj.fr',        'phone' => '+33 2 31 06 00 00', 'address' => '2 rue Saint-Ouen, 14000 Caen'],
            ['name' => 'FinTech Horizons SAS',      'industry' => 'Finance & Assurance',       'website' => 'https://fintech-horizons.fr',   'phone' => '+33 1 83 62 10 20', 'address' => '17 rue de la Bourse, 75002 Paris'],
            ['name' => 'EcoBuilding Constructions', 'industry' => 'BTP & Construction',        'website' => 'https://ecobuilding.fr',        'phone' => '+33 4 37 00 33 44', 'address' => '5 allée des Bâtisseurs, 38000 Grenoble'],
            ['name' => 'LogiMove Transport',        'industry' => 'Logistique & Transport',    'website' => 'https://logimove.fr',           'phone' => '+33 5 57 85 00 70', 'address' => 'Zone Portuaire, 33000 Bordeaux'],
            ['name' => 'StartupFactory Lyon',       'industry' => 'Incubateur & Startup',      'website' => 'https://startupfactory.fr',     'phone' => '+33 4 26 68 77 88', 'address' => 'EMLYON, 23 av. Guy de Collongue, 69130 Écully'],
        ];
        foreach ($companyDefs as $def) {
            $c = new Company();
            $c->setName($def['name'])
              ->setIndustry($def['industry'])
              ->setWebsite($def['website'])
              ->setPhone($def['phone'])
              ->setAddress($def['address']);
            $em->persist($c);
            $companies[] = $c;
        }

        $contacts = [];
        $contactDefs = [
            ['first' => 'Pierre',    'last' => 'Dubois',    'email' => 'pierre.dubois@tech-innovation.fr',  'phone' => '+33 6 12 34 56 78', 'company' => 0,    'source' => ContactSourceEnum::Manual, 'tags' => ['client', 'vip']],
            ['first' => 'Camille',   'last' => 'Leroy',     'email' => 'c.leroy@biomed-france.com',         'phone' => '+33 6 23 45 67 89', 'company' => 1,    'source' => ContactSourceEnum::Manual, 'tags' => ['prospect']],
            ['first' => 'François',  'last' => 'Moreau',    'email' => 'f.moreau@retail-connect.fr',        'phone' => '+33 6 34 56 78 90', 'company' => 2,    'source' => ContactSourceEnum::Order,  'tags' => ['client']],
            ['first' => 'Julie',     'last' => 'Chen',      'email' => 'julie.chen@tech-innovation.fr',     'phone' => '+33 6 45 67 89 01', 'company' => 0,    'source' => ContactSourceEnum::Manual, 'tags' => ['client']],
            ['first' => 'Marc',      'last' => 'Fontaine',  'email' => 'marc.fontaine@prospect.com',        'phone' => '+33 6 56 78 90 12', 'company' => null, 'source' => ContactSourceEnum::Form,   'tags' => ['prospect', 'newsletter']],
            ['first' => 'Isabelle',  'last' => 'Renard',    'email' => 'i.renard@nexus-digital.fr',         'phone' => '+33 6 67 89 01 23', 'company' => 3,    'source' => ContactSourceEnum::Manual, 'tags' => ['partenaire']],
            ['first' => 'David',     'last' => 'Beaumont',  'email' => 'd.beaumont@leclerc-nord.fr',        'phone' => '+33 6 78 90 12 34', 'company' => 4,    'source' => ContactSourceEnum::Manual, 'tags' => ['client']],
            ['first' => 'Nathalie',  'last' => 'Simon',     'email' => 'n.simon@clinique-sj.fr',            'phone' => '+33 6 89 01 23 45', 'company' => 5,    'source' => ContactSourceEnum::Form,   'tags' => ['prospect', 'newsletter']],
            ['first' => 'Antoine',   'last' => 'Garnier',   'email' => 'a.garnier@fintech-horizons.fr',     'phone' => '+33 6 90 12 34 56', 'company' => 6,    'source' => ContactSourceEnum::Manual, 'tags' => ['client', 'vip']],
            ['first' => 'Laure',     'last' => 'Michaud',   'email' => 'l.michaud@ecobuilding.fr',          'phone' => '+33 6 01 23 45 67', 'company' => 7,    'source' => ContactSourceEnum::Order,  'tags' => ['client']],
            ['first' => 'Sébastien', 'last' => 'Blanc',     'email' => 's.blanc@logimove.fr',               'phone' => '+33 6 12 23 34 45', 'company' => 8,    'source' => ContactSourceEnum::Manual, 'tags' => ['partenaire']],
            ['first' => 'Emma',      'last' => 'Rousseau',  'email' => 'e.rousseau@startupfactory.fr',      'phone' => '+33 6 23 34 45 56', 'company' => 9,    'source' => ContactSourceEnum::Form,   'tags' => ['prospect']],
            ['first' => 'Thomas',    'last' => 'Lambert',   'email' => 'tlambert@prospect.io',              'phone' => '+33 6 34 45 56 67', 'company' => null, 'source' => ContactSourceEnum::Form,   'tags' => ['prospect', 'newsletter']],
            ['first' => 'Céline',    'last' => 'Dupuis',    'email' => 'celine.dupuis@startup-prospect.fr', 'phone' => '+33 6 45 56 67 78', 'company' => null, 'source' => ContactSourceEnum::Order,  'tags' => ['client']],
            ['first' => 'Hugo',      'last' => 'Marchand',  'email' => 'h.marchand@tech-innovation.fr',     'phone' => '+33 6 56 67 78 89', 'company' => 0,    'source' => ContactSourceEnum::Manual, 'tags' => ['client']],
        ];
        $slugger = new AsciiSlugger();
        $contactTagsByLabel = [];
        $uniqueLabels = [];
        foreach ($contactDefs as $def) {
            foreach ($def['tags'] as $label) {
                $uniqueLabels[$label] = true;
            }
        }

        foreach (array_keys($uniqueLabels) as $label) {
            $contactTag = new ContactTag();
            $contactTag->setLabel($label)
                ->setSlug($slugger->slug($label)->lower()->toString())
                ->setColor('#6366F1');
            $em->persist($contactTag);
            $contactTagsByLabel[$label] = $contactTag;
        }

        foreach ($contactDefs as $def) {
            $c = new Contact();
            $c->setFirstName($def['first'])
              ->setLastName($def['last'])
              ->setEmail($def['email'])
              ->setPhone($def['phone'])
              ->setSource($def['source']);
            if (null !== $def['company']) {
                $c->setCompany($companies[$def['company']]);
            }

            foreach ($def['tags'] as $label) {
                $c->addContactTag($contactTagsByLabel[$label]);
            }

            $em->persist($c);
            $contacts[] = $c;
        }

        $dealDefs = [
            ['name' => 'Projet CRM Sur-mesure',          'stage' => DealStageEnum::Won,         'value' => '45000.00', 'contact' => 0, 'company' => 0,    'closing' => '-1 month'],
            ['name' => 'Refonte Site Web BioMed',         'stage' => DealStageEnum::Proposal,    'value' => '12000.00', 'contact' => 1, 'company' => 1,    'closing' => '+2 months'],
            ['name' => 'Solution E-commerce',             'stage' => DealStageEnum::Negotiation, 'value' => '28500.00', 'contact' => 2, 'company' => 2,    'closing' => '+3 weeks'],
            ['name' => 'Formation équipe dev',            'stage' => DealStageEnum::Qualified,   'value' => '4800.00',  'contact' => 3, 'company' => 0,    'closing' => '+6 weeks'],
            ['name' => 'Audit infrastructure SI',         'stage' => DealStageEnum::Lead,        'value' => '8000.00',  'contact' => 4, 'company' => null, 'closing' => '+2 months'],
            ['name' => 'Licence Aurora ERP — Tech Inno.', 'stage' => DealStageEnum::Won,         'value' => '99900.00', 'contact' => 0, 'company' => 0,    'closing' => '-2 months'],
            ['name' => 'Migration Cloud BioMed',          'stage' => DealStageEnum::Lost,        'value' => '35000.00', 'contact' => 1, 'company' => 1,    'closing' => '-3 weeks'],
            ['name' => 'Intégration Stripe Retail',       'stage' => DealStageEnum::Qualified,   'value' => '9500.00',  'contact' => 2, 'company' => 2,    'closing' => '+1 month'],
            ['name' => 'Déploiement GED entreprise',      'stage' => DealStageEnum::Proposal,    'value' => '18000.00', 'contact' => 3, 'company' => 0,    'closing' => '+5 weeks'],
            ['name' => 'Support & Maintenance annuel',    'stage' => DealStageEnum::Negotiation, 'value' => '24000.00', 'contact' => 4, 'company' => null, 'closing' => '+3 months'],
        ];
        foreach ($dealDefs as $def) {
            $d = new Deal();
            $d->setName($def['name'])
              ->setStage($def['stage'])
              ->setValue($def['value'])
              ->setContact($contacts[$def['contact']])
              ->setClosingDate(new DateTimeImmutable($def['closing']));
            if (null !== $def['company']) {
                $d->setCompany($companies[$def['company']]);
            }

            $em->persist($d);
        }

        $em->flush();

        $this->createContactActivity($em, $contacts, $users);

        return [$companies, $contacts];
    }

    private function createContactActivity(EntityManagerInterface $em, array $contacts, array $users): void
    {
        $now = new DateTimeImmutable();
        $events = [
            // Pierre (VIP, manual) — long-running client relationship
            [0, 'contact.created', 142, 0],
            [0, 'contact.updated', 96,  0],
            [0, 'contact.updated', 41,  1],
            [0, 'contact.updated', 4,   0],
            // Camille (prospect, manual)
            [1, 'contact.created', 73,  1],
            [1, 'contact.updated', 21,  1],
            // François (order)
            [2, 'contact.created', 38,  null],
            [2, 'contact.updated', 9,   0],
            // Julie (client, manual)
            [3, 'contact.created', 110, 0],
            [3, 'contact.updated', 60,  0],
            [3, 'contact.updated', 12,  1],
            // Marc (form, newsletter)
            [4, 'contact.created', 22,  null],
            [4, 'contact.updated', 6,   1],
            // Isabelle (partenaire)
            [5, 'contact.created', 65,  0],
            // David (client)
            [6, 'contact.created', 58,  1],
            [6, 'contact.updated', 14,  0],
            // Nathalie (form)
            [7, 'contact.created', 11,  null],
            // Antoine (VIP)
            [8, 'contact.created', 130, 0],
            [8, 'contact.updated', 76,  0],
            [8, 'contact.updated', 18,  1],
            [8, 'contact.updated', 1,   0],
            // Laure (order)
            [9, 'contact.created', 27,  null],
            [9, 'contact.updated', 5,   0],
            // Sébastien (partenaire)
            [10, 'contact.created', 48, 1],
            // Emma (form)
            [11, 'contact.created', 17, null],
            // Thomas (form)
            [12, 'contact.created', 9,  null],
            [12, 'contact.updated', 3,  0],
            // Céline (order)
            [13, 'contact.created', 4,  null],
            // Hugo (client tech-innov)
            [14, 'contact.created', 84, 0],
            [14, 'contact.updated', 30, 1],
        ];

        foreach ($events as [$contactIndex, $action, $daysAgo, $actorIndex]) {
            $contact = $contacts[$contactIndex];
            $actor = null !== $actorIndex ? ($users[$actorIndex] ?? null) : null;

            $log = new AuditLog(
                module: 'crm',
                action: $action,
                entityType: 'Contact',
                entityId: $contact->getId(),
                userId: $actor?->getId(),
                userEmail: $actor?->getEmail(),
                userName: $actor?->getName(),
                data: [
                    'name' => $contact->getFullName(),
                    'reference' => $contact->getReference(),
                    'source' => $contact->getSource()?->value,
                ],
            );

            $createdAt = $now->modify('-'.$daysAgo.' days');
            $reflection = new ReflectionProperty(AbstractAuditLog::class, 'createdAt');
            $reflection->setValue($log, $createdAt);

            $em->persist($log);
        }

        $em->flush();
    }
}
