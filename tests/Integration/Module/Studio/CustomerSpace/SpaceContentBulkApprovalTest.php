<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\CustomerSpace;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLink;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumn;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentComment;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItem;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentColumnRepository;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentItemRepository;
use Aurora\Tests\Integration\Concern\ResetsRateLimiters;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function json_decode;
use function parse_url;
use function sprintf;

/**
 * Valider plusieurs cartes d'un geste, et l'échéance de relecture.
 *
 * Deux sujets dans une classe parce qu'ils se tiennent : l'échéance est ce qui
 * décide de l'ordre dans lequel un client traite sa liste, et la liste est ce
 * qui rend l'action de masse possible.
 */
final class SpaceContentBulkApprovalTest extends IntegrationTestCase
{
    use ResetsRateLimiters;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private SpaceContentColumnRepository $columns;

    private SpaceContentItemRepository $items;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->columns = $container->get(SpaceContentColumnRepository::class);
        $this->items = $container->get(SpaceContentItemRepository::class);

        $this->resetRateLimiter('space_guest_write');
        $this->loginAdmin();
    }

    protected function tearDown(): void
    {
        foreach ([SpaceContentComment::class, SpaceContentItem::class, SpaceAccessLink::class, SpaceContentColumn::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        parent::tearDown();
    }

    public function testAClientApprovesSeveralAtOnce(): void
    {
        $space = $this->givenSpace();
        $first = $this->givenItem($space, 'Premier');
        $second = $this->givenItem($space, 'Deuxième');
        $untouched = $this->givenItem($space, 'Pas sélectionné');

        $url = $this->issue($space);
        $this->asGuest()->jsonRequest('POST', $this->approvePath($url), ['ids' => [$first, $second]]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(2, $this->payload()['approved']);

        $this->entityManager->clear();
        self::assertSame('approved', $this->items->find($first)->getApproval()->value);
        self::assertSame('approved', $this->items->find($second)->getApproval()->value);
        // Ce qui n'était pas coché n'a pas bougé : une action de masse porte
        // sur une sélection, pas sur un écran.
        self::assertSame('pending', $this->items->find($untouched)->getApproval()->value);
    }

    /** La carte d'un autre client est ignorée, jamais validée. */
    public function testACardFromAnotherSpaceIsIgnored(): void
    {
        $space = $this->givenSpace();
        $mine = $this->givenItem($space, 'La mienne');

        $theirs = $this->givenSpace('Autre client', '55217863900132');
        $foreign = $this->givenItem($theirs, 'La leur');

        $url = $this->issue($space);
        $this->asGuest()->jsonRequest('POST', $this->approvePath($url), ['ids' => [$mine, $foreign]]);

        self::assertSame(1, $this->payload()['approved']);

        $this->entityManager->clear();
        self::assertSame('approved', $this->items->find($mine)->getApproval()->value);
        self::assertSame('pending', $this->items->find($foreign)->getApproval()->value);
    }

    /**
     * Un lien en lecture seule reçoit le 404 d'un inconnu.
     *
     * Dire « vous pouvez lire mais pas répondre » serait vrai et apprendrait à
     * qui tient une adresse fuitée ce qu'il tient exactement.
     */
    public function testAReadOnlyLinkCannotApproveInBulk(): void
    {
        $space = $this->givenSpace();
        $item = $this->givenItem($space, 'Interdite');

        $url = $this->issue($space, canApprove: false);
        $this->asGuest()->jsonRequest('POST', $this->approvePath($url), ['ids' => [$item]]);

        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->entityManager->clear();
        self::assertSame('pending', $this->items->find($item)->getApproval()->value);
    }

    public function testAnEmptySelectionIsRefused(): void
    {
        $space = $this->givenSpace();
        $url = $this->issue($space);

        $this->asGuest()->jsonRequest('POST', $this->approvePath($url), ['ids' => []]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Le retard se compte sur les trois mêmes conditions que l'attente, plus
     * l'échéance dépassée. Une carte déjà validée n'est en retard de rien.
     */
    public function testLatenessNeedsADeadlineAndNoAnswer(): void
    {
        $space = $this->givenSpace();
        $late = $this->givenItem($space, 'En retard');
        $this->schedule($space, $late, '2026-12-01T09:00', '2020-01-01T09:00');

        $onTime = $this->givenItem($space, 'Dans les temps');
        $this->schedule($space, $onTime, '2026-12-01T09:00', '2099-01-01T09:00');

        $noDeadline = $this->givenItem($space, 'Sans échéance');
        $this->schedule($space, $noDeadline, '2026-12-01T09:00', null);

        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(CustomerSpace::class)->find($space->getId());

        self::assertSame(3, $this->items->countAwaitingApproval($stored));
        self::assertSame(1, $this->items->countLateForReview($stored, new DateTimeImmutable()));

        // Répondue, elle sort du retard sans que son échéance ait bougé.
        $url = $this->issue($space);
        $this->asGuest()->jsonRequest('POST', $this->approvePath($url), ['ids' => [$late]]);

        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(CustomerSpace::class)->find($space->getId());
        self::assertSame(0, $this->items->countLateForReview($stored, new DateTimeImmutable()));
    }

    private function loginAdmin(): void
    {
        $admin = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');
    }

    private function asGuest(): KernelBrowser
    {
        $this->client->getCookieJar()->clear();
        $this->client->setServerParameter('HTTP_X-Requested-With', 'XMLHttpRequest');

        return $this->client;
    }

    private function issue(CustomerSpace $space, bool $canApprove = true): string
    {
        $this->loginAdmin();

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/issue', $space->getId()), [
            'recipientEmail' => 'camille@societe.test',
            'label' => 'Camille, gérante',
            'canApprove' => $canApprove,
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return $this->payload()['url'];
    }

    private function approvePath(string $url): string
    {
        return sprintf('%s/content/approve', (string) parse_url($url, PHP_URL_PATH));
    }

    private function givenSpace(string $customerName = 'Client en lot', string $siret = '73282932000074'): CustomerSpace
    {
        $this->loginAdmin();

        $customer = new Customer();
        $customer
            ->setLegalName($customerName)
            ->setSiret($siret)
            ->setContractualEmail('bulk@example.test');

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/backend/studio/spaces/create', [
            'name' => 'Espace de '.$customerName,
            'customerId' => $customer->getId(),
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $space = $this->entityManager->getRepository(CustomerSpace::class)
            ->find($this->payload()['space']['id']);
        self::assertInstanceOf(CustomerSpace::class, $space);

        return $space;
    }

    private function givenItem(CustomerSpace $space, string $title): int
    {
        $this->loginAdmin();

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/create', $space->getId()), [
            'title' => $title,
            'columnId' => $this->columns->findForSpace($space)[0]->getId(),
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        foreach ($this->payload()['items'] as $item) {
            if ($title === $item['title']) {
                return $item['id'];
            }
        }

        self::fail(sprintf('La carte "%s" n\'a pas été créée.', $title));
    }

    private function schedule(CustomerSpace $space, int $itemId, string $scheduledAt, ?string $reviewBy): void
    {
        $this->loginAdmin();

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/%d/update', $space->getId(), $itemId), [
            'title' => $this->items->find($itemId)->getTitle(),
            'columnId' => $this->columns->findForSpace($space)[0]->getId(),
            'scheduledAt' => $scheduledAt,
            'reviewBy' => $reviewBy,
            'showOnCalendar' => true,
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
