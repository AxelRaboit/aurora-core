<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\CustomerSpace;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLink;
use Aurora\Module\Studio\SpaceAccess\Repository\SpaceAccessLinkRepository;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumn;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentComment;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItem;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentColumnRepository;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentCommentRepository;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentItemRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function json_decode;
use function parse_url;
use function preg_replace;
use function sprintf;
use function str_repeat;

/**
 * What a client answers, and everything the answer must not do.
 *
 * The verdict is the first thing a stranger with a leaked address could write,
 * so most of this is about its walls: the link's own right, the space it opens,
 * and the fact that an approval is of a wording rather than of a card.
 */
final class SpaceContentApprovalTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private SpaceAccessLinkRepository $links;

    private SpaceContentColumnRepository $columns;

    private SpaceContentItemRepository $items;

    private SpaceContentCommentRepository $comments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();

        $container = static::getContainer();

        $admin = $container->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->links = $container->get(SpaceAccessLinkRepository::class);
        $this->columns = $container->get(SpaceContentColumnRepository::class);
        $this->items = $container->get(SpaceContentItemRepository::class);
        $this->comments = $container->get(SpaceContentCommentRepository::class);
    }

    protected function tearDown(): void
    {
        foreach ([SpaceContentComment::class, SpaceContentItem::class, SpaceAccessLink::class, SpaceContentColumn::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        parent::tearDown();
    }

    public function testAClientApprovesAndTheStudioSeesIt(): void
    {
        $space = $this->givenSpace();
        $item = $this->givenItem($space, 'Portes ouvertes');
        $answer = $this->answerPathFor($space, $item['id']);

        $guest = $this->asGuest();
        // Two calls, because they are two things: a message on the thread,
        // then the verdict. The screen asks for them in that order too.
        $guest->jsonRequest('POST', $this->commentPathFrom($answer), ['body' => 'Parfait.']);
        $guest->jsonRequest('POST', $answer, ['approval' => 'approved']);

        self::assertSame(200, $guest->getResponse()->getStatusCode());

        $this->entityManager->clear();
        $stored = $this->items->find($item['id']);

        self::assertSame('approved', $stored->getApproval()->value);
        self::assertNotNull($stored->getApprovalAt());

        // The words are a message on the thread rather than a column on the
        // verdict, which is what lets the verdict be reset without losing them.
        $thread = $this->comments->findForSpaceByItem($stored->getSpace())[$item['id']];
        self::assertCount(1, $thread);
        self::assertSame('Parfait.', $thread[0]->getBody());
        self::assertTrue($thread[0]->isFromClient());
        // Le nom du lien, jamais son adresse : c'est ce que lisent les autres
        // invités du même espace.
        self::assertSame('Camille, gérante', $thread[0]->getAuthorLabel());
        // Who answered, by the address the link was sent to: there is no
        // account behind this.
        self::assertSame('camille@societe.test', $stored->getApprovalByLink()->getRecipientEmail());
    }

    public function testTheAnswerNeverMovesTheCard(): void
    {
        $space = $this->givenSpace();
        $columns = $this->columns->findForSpace($space);
        $item = $this->givenItem($space, 'À ne pas déplacer');

        $guest = $this->asGuest();
        $guest->jsonRequest('POST', $this->answerPathFor($space, $item['id']), [
            'approval' => 'approved',
        ]);

        $this->entityManager->clear();
        $stored = $this->items->find($item['id']);

        // An opinion, not a state machine: a client clicking the wrong button
        // would otherwise have scheduled a publication.
        self::assertSame($columns[0]->getId(), $stored->getColumn()->getId());
        self::assertNull($stored->getScheduledAt());
    }

    public function testRewritingTheTextClearsTheAnswer(): void
    {
        $space = $this->givenSpace();
        $item = $this->givenItem($space, 'Texte d origine');

        $guest = $this->asGuest();
        $guest->jsonRequest('POST', $this->answerPathFor($space, $item['id']), ['approval' => 'approved']);

        $this->loginAdmin();
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/%d/update', $space->getId(), $item['id']), [
            'title' => 'Texte reecrit',
            'columnId' => $this->columns->findForSpace($space)[0]->getId(),
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->entityManager->clear();
        $stored = $this->items->find($item['id']);

        // An approval is of a wording. Keeping it after a rewrite would tell
        // the board a client agreed to something they never read.
        self::assertSame('pending', $stored->getApproval()->value);
    }

    public function testRewritingTheTextKeepsWhatTheClientWrote(): void
    {
        $space = $this->givenSpace();
        $item = $this->givenItem($space, 'Texte a revoir');

        $guest = $this->asGuest();
        $path = $this->answerPathFor($space, $item['id']);
        $guest->jsonRequest('POST', $this->commentPathFrom($path), ['body' => 'Le ton est trop formel.']);
        $guest->jsonRequest('POST', $path, ['approval' => 'changes_requested']);

        $this->loginAdmin();
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/%d/update', $space->getId(), $item['id']), [
            'title' => 'Texte repris',
            'columnId' => $this->columns->findForSpace($space)[0]->getId(),
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->entityManager->clear();
        $stored = $this->items->find($item['id']);

        // This is the defect the thread exists to fix. The note used to live on
        // the verdict and was cleared with it - so the instruction disappeared
        // at the exact moment the studio was acting on it.
        self::assertSame('pending', $stored->getApproval()->value);

        $thread = $this->comments->findForSpaceByItem($space)[$item['id']];
        self::assertCount(1, $thread);
        self::assertSame('Le ton est trop formel.', $thread[0]->getBody());
    }

    public function testMovingACardLeavesTheAnswerAlone(): void
    {
        $space = $this->givenSpace();
        $columns = $this->columns->findForSpace($space);
        $item = $this->givenItem($space, 'Validé puis déplacé');

        $guest = $this->asGuest();
        $guest->jsonRequest('POST', $this->answerPathFor($space, $item['id']), ['approval' => 'approved']);

        $this->loginAdmin();
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/reorder', $space->getId()), [
            'columnId' => $columns[3]->getId(),
            'itemIds' => [$item['id']],
        ]);

        $this->entityManager->clear();
        // Only the title and the copy count: they are what the client was
        // shown. Scheduling it or moving it along the board is the studio
        // acting on the answer, not changing what was answered.
        self::assertSame('approved', $this->items->find($item['id'])->getApproval()->value);
    }

    public function testALinkWithoutTheRightCannotAnswer(): void
    {
        $space = $this->givenSpace();
        $item = $this->givenItem($space, 'Lecture seule');
        $answer = $this->answerPathFor($space, $item['id'], canApprove: false);

        $guest = $this->asGuest();
        $guest->jsonRequest('POST', $answer, ['approval' => 'approved']);

        // The same 404 a stranger gets. Saying "you may read but not answer"
        // would tell somebody holding a leaked address exactly what they have.
        self::assertSame(404, $guest->getResponse()->getStatusCode());

        $this->entityManager->clear();
        self::assertSame('pending', $this->items->find($item['id'])->getApproval()->value);
    }

    public function testAReadOnlyLinkIsHandedNoEndpointAtAll(): void
    {
        $space = $this->givenSpace();
        $this->givenItem($space, 'Rien à poster');
        $url = $this->issue($space, canApprove: false);

        $guest = $this->asGuest();
        $guest->request('GET', (string) parse_url($url, PHP_URL_PATH));

        // Not a hidden button: the address never reaches the page.
        self::assertStringNotContainsString('/answer', (string) $guest->getResponse()->getContent());
    }

    public function testACardOfAnotherSpaceCannotBeAnswered(): void
    {
        $mine = $this->givenSpace();
        $theirs = $this->givenSpace('Voisin', '39860733100024');

        $foreign = $this->givenItem($theirs, 'Pas à moi');
        $url = $this->issue($mine);

        $guest = $this->asGuest();
        $guest->jsonRequest('POST', $this->answerPathFromUrl($url, $foreign['id']), ['approval' => 'approved']);

        // The one interesting attack on this endpoint, and it stops in the
        // Manager rather than in the controller.
        self::assertSame(404, $guest->getResponse()->getStatusCode());

        $this->entityManager->clear();
        self::assertSame('pending', $this->items->find($foreign['id'])->getApproval()->value);
    }

    public function testAWrongSecretCannotAnswer(): void
    {
        $space = $this->givenSpace();
        $item = $this->givenItem($space, 'Protégé');
        $this->issue($space);

        $selector = $this->links->findAll()[0]->getSelector();

        $guest = $this->asGuest();
        $guest->jsonRequest(
            'POST',
            sprintf('/spaces/%s/%s/content/%d/answer', $selector, str_repeat('a', 64), $item['id']),
            ['approval' => 'approved'],
        );

        self::assertSame(404, $guest->getResponse()->getStatusCode());
    }

    public function testPendingIsNotSomethingAnybodyAnswers(): void
    {
        $space = $this->givenSpace();
        $item = $this->givenItem($space, 'Sans réponse');

        $guest = $this->asGuest();
        $guest->jsonRequest('POST', $this->answerPathFor($space, $item['id']), ['approval' => 'pending']);

        // Silence is what nobody answering looks like, so it is not a verdict:
        // a client who changes their mind says the other thing.
        self::assertSame(422, $guest->getResponse()->getStatusCode());
    }

    private function loginAdmin(): void
    {
        $admin = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        $this->client->loginUser($admin, 'admin');
    }

    private function asGuest(): KernelBrowser
    {
        $this->client->getCookieJar()->clear();

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

    private function answerPathFor(CustomerSpace $space, int $itemId, bool $canApprove = true): string
    {
        return $this->answerPathFromUrl($this->issue($space, $canApprove), $itemId);
    }

    /**
     * The thread's address, derived from the verdict's.
     *
     * The two hang off the same card, so one is the other with its last segment
     * swapped - which keeps the test from having to mint a second link.
     */
    private function commentPathFrom(string $answerPath): string
    {
        return preg_replace('#/answer$#', '/comments', $answerPath);
    }

    private function answerPathFromUrl(string $url, int $itemId): string
    {
        return sprintf('%s/content/%d/answer', (string) parse_url($url, PHP_URL_PATH), $itemId);
    }

    private function givenSpace(string $customerName = 'Client validant', string $siret = '73282932000074'): CustomerSpace
    {
        $this->loginAdmin();

        $customer = new Customer();
        $customer
            ->setLegalName($customerName)
            ->setSiret($siret)
            ->setContractualEmail('approval@example.test');

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

    /** @return array<string, mixed> */
    private function givenItem(CustomerSpace $space, string $title): array
    {
        $this->loginAdmin();

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/create', $space->getId()), [
            'title' => $title,
            'columnId' => $this->columns->findForSpace($space)[0]->getId(),
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        foreach ($this->payload()['items'] as $item) {
            if ($item['title'] === $title) {
                return $item;
            }
        }

        self::fail(sprintf('the card "%s" was not in the answer', $title));
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
