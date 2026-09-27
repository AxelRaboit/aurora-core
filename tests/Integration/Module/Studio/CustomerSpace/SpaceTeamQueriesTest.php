<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\CustomerSpace;

use Aurora\Core\Notification\Entity\Notification;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceMember;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceMemberRoleEnum;
use Aurora\Module\Studio\CustomerSpace\Serializer\CustomerSpaceSerializerInterface;
use Aurora\Module\Studio\CustomerSpace\Service\SpaceActivityNotifier;
use Aurora\Tests\Integration\Concern\CreatesTestUsers;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

use function array_filter;
use function array_values;
use function count;
use function preg_match;
use function sprintf;
use function str_contains;

/**
 * A space's team is read in one query, however many people are on it.
 *
 * Each member's account was loaded on its own, and whether each of them still
 * had an unread piece of news was asked person by person: on every screen of
 * the space, and on every write of its client.
 */
final class SpaceTeamQueriesTest extends IntegrationTestCase
{
    use CreatesTestUsers;

    private EntityManagerInterface $entityManager;

    /** @var list<User> */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery(sprintf("DELETE FROM %s n WHERE n.type = 'studio.space.late_review'", Notification::class))->execute();
        foreach ([CustomerSpaceMember::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }
        foreach ($this->people as $person) {
            $managed = $this->entityManager->find(User::class, $person->getId());
            if (null !== $managed) {
                $this->entityManager->remove($managed);
            }
        }
        $this->entityManager->flush();

        parent::tearDown();
    }

    public function testTheTeamIsReadOnceToBeToldAndToBeShown(): void
    {
        $customer = new Customer();
        $customer->setLegalName('Client en équipe')->setSiret('73282932000074')->setContractualEmail('equipe@example.test');
        $space = new CustomerSpace();
        $space->setName('Espace en équipe')->setCustomer($customer)->setTimezone('Europe/Paris');
        $this->entityManager->persist($customer);
        $this->entityManager->persist($space);

        foreach (['une', 'deux', 'trois'] as $name) {
            $person = $this->createTestUser('equipe-'.$name);
            $this->people[] = $person;
            $member = new CustomerSpaceMember();
            $member->setUser($person)->setRole(CustomerSpaceMemberRoleEnum::Member);
            $space->addMember($member);
            $this->entityManager->persist($member);
        }
        $this->entityManager->flush();
        $spaceId = (int) $space->getId();

        $this->entityManager->clear();
        $space = $this->entityManager->find(CustomerSpace::class, $spaceId);
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        static::getContainer()->get(SpaceActivityNotifier::class)->reviewsLate($space, 2);
        $shape = static::getContainer()->get(CustomerSpaceSerializerInterface::class)->serialize($space);

        self::assertCount(3, $shape['members']);

        $queries = $holder->getData()['default'] ?? [];
        $userLoads = array_filter($queries, static fn (array $query): bool => 1 === preg_match('/FROM core_users t0 /', (string) $query['sql']));
        $unreadChecks = array_filter($queries, static fn (array $query): bool => str_contains((string) $query['sql'], 'FROM core_notifications') && str_starts_with((string) $query['sql'], 'SELECT'));

        self::assertSame([], array_values($userLoads), 'no member is loaded on their own');
        self::assertSame(1, count($unreadChecks), 'the unread news of the team, asked once');
    }
}
