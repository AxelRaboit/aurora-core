<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\CustomerSpace;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLink;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceAccess\Manager\SpaceAccessLinkManagerInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentAttachment;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumn;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItem;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentColumnRepository;
use Aurora\Tests\Integration\Concern\ResetsRateLimiters;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function base64_decode;
use function bin2hex;
use function file_put_contents;
use function json_decode;
use function random_bytes;
use function sprintf;
use function sys_get_temp_dir;

/**
 * What an access link does not show.
 *
 * **The side that cannot be seen from the studio.** A privacy setting is
 * checked by opening the client's page, which nobody does on every deploy; a
 * regression there would therefore be silent, and found by the client
 * themselves. That is exactly what tests must carry instead.
 *
 * Two guarantees, and one of them has two halves: a step marked internal
 * removes **its cards** as well as itself, otherwise they would stay in the
 * client's calendar, which reads them by their date and not by their step.
 */
final class SpaceClientVisibilityTest extends IntegrationTestCase
{
    use ResetsRateLimiters;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private SpaceContentColumnRepository $columnRepository;

    private SpaceAccessLinkManagerInterface $links;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();

        // Without this the kernel reboots between two requests, and the space
        // created by the screen stops being the same instance as this test's:
        // the link manager then sees it as an unknown entity.
        $this->client->disableReboot();

        $container = static::getContainer();

        $admin = $container->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');

        $this->entityManager = $container->get(EntityManagerInterface::class);
        // The rate limiter's counter outlives the process: a class that writes
        // as a guest spends a shared hourly budget, and turns red on the
        // third run within the hour - through a 429 on a route the test did
        // not mean to exercise.
        $this->resetRateLimiter('space_guest_write');

        // The browser sets this header on every call, and public routes
        // require it: what protects them is a secret in the address, and an
        // address can be forwarded.
        $this->client->setServerParameter('HTTP_X-Requested-With', 'XMLHttpRequest');

        $this->columnRepository = $container->get(SpaceContentColumnRepository::class);
        $this->links = $container->get(SpaceAccessLinkManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ([SpaceContentAttachment::class, SpaceContentItem::class, SpaceContentColumn::class, SpaceAccessLink::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        parent::tearDown();
    }

    public function testAStepMarkedInternalTakesItsCardsOutOfTheClientPage(): void
    {
        $space = $this->givenSpace();
        $columns = $this->columnRepository->findForSpace($space);

        // The two steps a new space shows the client: Relecture and Publié.
        // All the others are born hidden.
        $open = $columns[2];
        $internal = $columns[4];

        $this->givenItem($space, $open, 'Ce que le client voit');
        $this->givenItem($space, $internal, 'Relecture juridique interne');

        // A single link, read twice: what the client sees changes because the
        // setting changes, and not because they were given another address.
        // That is also what this sets out to prove.
        $link = $this->givenLink($space);

        // The title in the page, and nothing cleverer: that is the guarantee
        // as a client observes it, and it does not depend on any particular
        // way of writing the data into the template.
        $before = $this->clientPage($link);
        self::assertStringContainsString('Ce que le client voit', $before);
        self::assertStringContainsString('Relecture juridique interne', $before);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/columns/%d/update', $space->getId(), $internal->getId()), [
            'name' => $internal->getName(),
            'visibleToClient' => false,
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $after = $this->clientPage($link);

        // The step disappears, and its card with it: that is the half people
        // forget, and the one that would leave the card in the calendar.
        self::assertStringContainsString('Ce que le client voit', $after);
        self::assertStringNotContainsString('Relecture juridique interne', $after);
        self::assertStringNotContainsString($internal->getName(), $after);
    }

    /**
     * An internal step also takes its thread and its files with it.
     *
     * **This is the half that was missing, and the most serious.** Items went
     * through the visible-columns filter; comments and attachments did not.
     * The thread of a step marked internal and its files therefore went into
     * the source of the client's page, with their download addresses -
     * invisible in use, since the screen did not know the item, and whole for
     * anyone reading the HTML.
     *
     * The test closes both halves: what the page carries, and what the address
     * returns. Filtering the payload without closing the route would only have
     * hidden the link, and an attachment id is a small integer.
     */
    public function testAnInternalStepTakesItsThreadAndItsFilesOutOfTheClientPage(): void
    {
        $space = $this->givenSpace();
        $columns = $this->columnRepository->findForSpace($space);
        $internal = $columns[1];

        $this->givenItem($space, $internal, 'Relecture juridique interne');
        $item = $this->entityManager->getRepository(SpaceContentItem::class)
            ->findOneBy(['title' => 'Relecture juridique interne']);
        self::assertInstanceOf(SpaceContentItem::class, $item);

        // A thread and a file on this item, added by the studio.
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/%d/comments', $space->getId(), $item->getId()), [
            'body' => 'Attention au nom du dirigeant dans le paragraphe deux.',
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request(
            'POST',
            sprintf('/workspace/%d/content/%d/attachments/upload', $space->getId(), $item->getId()),
            [],
            ['file' => $this->aJpeg('note-interne.jpg')],
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $attachment = $this->entityManager->getRepository(SpaceContentAttachment::class)
            ->findOneBy(['item' => $item]);
        self::assertInstanceOf(SpaceContentAttachment::class, $attachment);

        $link = $this->givenLink($space);

        // The step becomes internal.
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/columns/%d/update', $space->getId(), $internal->getId()), [
            'name' => $internal->getName(),
            'visibleToClient' => false,
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $page = $this->clientPage($link);

        self::assertStringNotContainsString('Attention au nom du dirigeant', $page, 'le fil est dans la page');
        self::assertStringNotContainsString('note-interne.jpg', $page, 'le fichier est dans la page');

        // And the address, which the page no longer shows, returns nothing.
        $this->client->request('GET', sprintf(
            '/spaces/%s/%s/attachments/%d/file',
            $link->getSelector(),
            (string) $link->getPlainToken(),
            $attachment->getId(),
        ));

        self::assertSame(404, $this->client->getResponse()->getStatusCode(), "l'adresse du fichier répond encore");
    }

    /**
     * An item the page does not show cannot be approved, commented on or
     * given a file. The three writes checked the space and not the step: an
     * item number was enough to answer on work the studio had not shown.
     */
    public function testACardOfAnInternalStepCannotBeAnsweredOrCommented(): void
    {
        $space = $this->givenSpace();
        $internal = $this->columnRepository->findForSpace($space)[1];
        $this->givenItem($space, $internal, 'Brouillon interne');
        $item = $this->entityManager->getRepository(SpaceContentItem::class)->findOneBy(['title' => 'Brouillon interne']);
        self::assertInstanceOf(SpaceContentItem::class, $item);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/columns/%d/update', $space->getId(), $internal->getId()), [
            'name' => $internal->getName(),
            'visibleToClient' => false,
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $link = $this->givenLink($space);
        $base = sprintf('/spaces/%s/%s/content', $link->getSelector(), (string) $link->getPlainToken());
        $fromThePage = ['HTTP_X-Requested-With' => 'XMLHttpRequest'];

        $this->client->jsonRequest('POST', sprintf('%s/%d/answer', $base, $item->getId()), ['approval' => 'approved'], $fromThePage);
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'approving');

        $this->client->jsonRequest('POST', sprintf('%s/%d/comments', $base, $item->getId()), ['body' => 'Vu'], $fromThePage);
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'commenting');

        $this->client->jsonRequest('POST', $base.'/approve', ['ids' => [$item->getId()]], $fromThePage);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, json_decode((string) $this->client->getResponse()->getContent(), true)['approved'], 'approving in bulk');
    }

    /**
     * The right to see the Drive, on the three routes that serve it.
     *
     * The same 404 as an unknown link, and not an explicit refusal: saying
     * "you may read but not this" tells whoever holds a leaked address what
     * they are holding.
     */
    public function testALinkWithoutTheDriveRightIsRefusedOnEveryDriveRoute(): void
    {
        $space = $this->givenSpace();
        $space->setDriveFolderId('un-dossier-partage');
        $this->entityManager->flush();

        $refused = $this->links->issue(
            $space,
            'sans-drive@example.test',
            'Second lecteur',
            30,
            canApprove: true,
            canComment: true,
            canSeeDrive: false,
        );

        foreach (['', '/archive', '/un-fichier'] as $suffix) {
            $this->client->request('GET', sprintf(
                '/spaces/%s/%s/drive%s',
                $refused->getSelector(),
                (string) $refused->getPlainToken(),
                $suffix,
            ));

            self::assertSame(
                404,
                $this->client->getResponse()->getStatusCode(),
                sprintf('la route "drive%s" laisse passer', $suffix),
            );
        }
    }

    /** And the granted right does return something, otherwise the previous test proves nothing. */
    public function testALinkWithTheDriveRightReachesTheListing(): void
    {
        $space = $this->givenSpace();
        $space->setDriveFolderId('un-dossier-partage');
        $this->entityManager->flush();

        $allowed = $this->links->issue(
            $space,
            'avec-drive@example.test',
            'Le client',
            30,
            canApprove: true,
            canComment: true,
            canSeeDrive: true,
        );

        $this->client->request('GET', sprintf(
            '/spaces/%s/%s/drive',
            $allowed->getSelector(),
            (string) $allowed->getPlainToken(),
        ));

        // 200 even without the integration connected: the route answers an
        // empty list, which is a state of the screen and not a refusal.
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /** The client's page, as it is served to them. */
    private function clientPage(SpaceAccessLinkInterface $link): string
    {
        $this->client->request('GET', sprintf(
            '/spaces/%s/%s',
            $link->getSelector(),
            (string) $link->getPlainToken(),
        ));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * A link, on the space as the entity manager knows it.
     *
     * Reloaded rather than reused: the preceding HTTP requests clear the
     * manager, and the earlier instance then passes for an unknown entity
     * when the link is issued.
     */
    private function givenLink(CustomerSpace $space, string $email = 'client@example.test'): SpaceAccessLinkInterface
    {
        $fresh = $this->entityManager->getRepository(CustomerSpace::class)->find($space->getId());
        self::assertInstanceOf(CustomerSpace::class, $fresh);

        return $this->links->issue($fresh, $email, 'Le client', 30, true, true);
    }

    /** The smallest file the sniffer calls a JPEG. */
    private function aJpeg(string $name): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.bin2hex(random_bytes(4)).'-'.$name;
        file_put_contents($path, (string) base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
            .'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAA'
            .'AAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==',
            true,
        ));

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    private function givenSpace(): CustomerSpace
    {
        $customer = new Customer();
        $customer
            ->setLegalName('Client de la visibilité')
            ->setSiret('73282932000074')
            ->setContractualEmail('visibilite@example.test');

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/suite/studio/spaces/create', [
            'name' => 'Espace de la visibilité',
            'customerId' => $customer->getId(),
            'timezone' => 'Europe/Paris',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        $space = $this->entityManager->getRepository(CustomerSpace::class)->find($payload['space']['id']);
        self::assertInstanceOf(CustomerSpace::class, $space);

        return $space;
    }

    private function givenItem(CustomerSpace $space, SpaceContentColumn $column, string $title): void
    {
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/create', $space->getId()), [
            'title' => $title,
            'columnId' => $column->getId(),
            // Dated: the client's page only shows its calendar.
            'scheduledAt' => '2026-12-01T10:00',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }
}
