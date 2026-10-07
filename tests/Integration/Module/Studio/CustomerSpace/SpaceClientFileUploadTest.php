<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\CustomerSpace;

use Aurora\Core\Notification\Entity\Notification;
use Aurora\Module\Dev\Audit\Entity\AuditLog;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceMember;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceMemberRoleEnum;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLink;
use Aurora\Module\Studio\SpaceAccess\Manager\SpaceAccessLinkManagerInterface;
use Aurora\Module\Studio\SpaceAccess\View\PublicSpaceViewBuilder;
use Aurora\Module\Studio\SpaceFile\Entity\SpaceFile;
use Aurora\Module\Studio\SpaceFile\Repository\SpaceFileRepository;
use Aurora\Module\Studio\SpaceFile\View\SpaceFilesViewBuilder;
use Aurora\Tests\Integration\Concern\ResetsRateLimiters;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function explode;
use function json_decode;
use function parse_url;
use function sprintf;

use const PHP_URL_PATH;

/**
 * A file the client sends to the space itself, from the Files tab of their page.
 *
 * The walls are the ones of a file on a card (the link's right, a preview that
 * never sends, the header guard, the policy on the bytes); what is new is where
 * the file lands: on the space, signed as the client's, visible on both sides,
 * announced to the team and written in the audit log.
 */
final class SpaceClientFileUploadTest extends IntegrationTestCase
{
    use ResetsRateLimiters;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private SpaceFileRepository $files;

    private User $admin;

    private string $workDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = static::getContainer();

        $admin = $container->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->admin = $admin;
        $this->client->loginUser($admin, 'admin');

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->files = $container->get(SpaceFileRepository::class);

        // The upload limiter counts per hour and outlives the process.
        $this->resetRateLimiter('space_guest_upload');

        $this->workDirectory = sys_get_temp_dir().'/aurora-client-file-'.bin2hex(random_bytes(4));
        mkdir($this->workDirectory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDirectory.'/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->workDirectory)) {
            rmdir($this->workDirectory);
        }

        $this->entityManager->createQuery(sprintf('DELETE FROM %s n WHERE n.type LIKE :prefix', Notification::class))
            ->setParameter('prefix', 'studio.space.%')
            ->execute();
        $this->entityManager->createQuery(sprintf("DELETE FROM %s a WHERE a.entityType = 'SpaceFile'", AuditLog::class))->execute();

        foreach ([SpaceFile::class, SpaceAccessLink::class, CustomerSpaceMember::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        parent::tearDown();
    }

    /**
     * No right, no address on the page and a stranger's answer from the route:
     * saying "you may read but not send" would tell whoever holds a leaked
     * address what they hold.
     */
    public function testWithoutTheRightThereIsNoButtonAndTheRouteRefuses(): void
    {
        [$space, $url] = $this->givenLinkedSpace(canUpload: false);

        self::assertNull($this->pageProps($url)['spaceFileUploadPath'], 'no upload address, so no button');

        $this->send($url, $this->aFile('logo.jpg'));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->files->count(['space' => $space]));
    }

    /**
     * The file is filed like a studio upload (a draft in the space's own GED
     * folder), signed with the link's label, and read back by both sides: the
     * client's list and its address, the studio's Files tab marked as the
     * client's.
     */
    public function testAFileSentByTheClientLandsOnTheSpaceVisibleToBothSides(): void
    {
        [$space, $url] = $this->givenLinkedSpace(canUpload: true);

        self::assertSame((string) parse_url($url, PHP_URL_PATH).'/files', $this->pageProps($url)['spaceFileUploadPath']);

        $this->send($url, $this->aFile('logo.jpg'));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $listed = $this->payload()['spaceFiles'];
        self::assertCount(1, $listed);
        self::assertTrue($listed[0]['fromClient']);
        self::assertSame('Camille, gérante', $listed[0]['author'], 'the label, never the address');
        self::assertArrayNotHasKey('documentId', $listed[0]);

        $file = $this->files->findOneBy(['space' => $space]);
        self::assertInstanceOf(SpaceFile::class, $file);
        self::assertTrue($file->isFromClient());
        self::assertTrue($file->isShownToClient());
        self::assertNotNull($file->getAuthorLink());

        $document = $file->getDocument();
        self::assertInstanceOf(Document::class, $document);
        self::assertSame(DocumentStatusEnum::Draft, $document->getStatus());
        // Read again through the request's own manager: the folder was opened
        // on the space by that upload, after this test loaded the space.
        $current = static::getContainer()->get(EntityManagerInterface::class)->find(CustomerSpace::class, $space->getId());
        self::assertInstanceOf(CustomerSpace::class, $current);
        self::assertNotNull($current->getDocumentFolder());
        self::assertSame($current->getDocumentFolder()->getId(), $document->getFolder()?->getId(), 'filed in the space folder, like a studio upload');

        // The client reads it back through their link.
        $this->asGuest()->request('GET', (string) parse_url($listed[0]['url'], PHP_URL_PATH));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // And the studio's Files tab lists it, shown to the client and theirs.
        $studio = static::getContainer()->get(SpaceFilesViewBuilder::class)->files($space);
        self::assertCount(1, $studio);
        self::assertTrue($studio[0]['fromClient']);
        self::assertTrue($studio[0]['visibleToClient']);
    }

    /** The team hears about it, as about the client's other gestures, and the log keeps it. */
    public function testTheTeamIsToldAndTheGestureIsAudited(): void
    {
        [$space, $url] = $this->givenLinkedSpace(canUpload: true);
        $this->givenMember($space);

        $this->send($url, $this->aFile('photo.jpg'));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $notification = $this->entityManager->getRepository(Notification::class)->findOneBy(['type' => 'studio.space.upload']);
        self::assertInstanceOf(Notification::class, $notification);
        self::assertSame(sprintf('/workspace/%d?view=files', $space->getId()), $notification->getUrl());
        self::assertStringContainsString('Camille, gérante', $notification->getTitle());
        self::assertStringContainsString('photo.jpg', $notification->getTitle());

        $entry = $this->entityManager->getRepository(AuditLog::class)->findOneBy(['action' => 'space_file.sent_by_client']);
        self::assertInstanceOf(AuditLog::class, $entry);
        self::assertSame('studio', $entry->getModule());
        self::assertTrue($entry->getData()['fromClient'] ?? null);
    }

    /**
     * A preview copies the right so the studio sees the client's page, and
     * still never sends anything in the client's name.
     */
    public function testAPreviewCannotSendEvenThoughItCarriesTheRight(): void
    {
        [$space] = $this->givenLinkedSpace(canUpload: true);
        // Through the current container's manager, the one the link manager
        // writes with: the issuing request rebooted the kernel.
        $source = static::getContainer()->get(EntityManagerInterface::class)->getRepository(SpaceAccessLink::class)->findOneBy(['space' => $space->getId()]);
        self::assertInstanceOf(SpaceAccessLink::class, $source);

        $preview = static::getContainer()->get(SpaceAccessLinkManagerInterface::class)->preview($source);
        self::assertTrue($preview->canUpload());

        $this->send(sprintf('/spaces/%s/%s', $preview->getSelector(), (string) $preview->getPlainToken()), $this->aFile('logo.jpg'));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->files->count(['space' => $space]));
    }

    /** Without the header the page's own requests carry, another site's form could post here. */
    public function testARequestWithoutThePageHeaderIsRefused(): void
    {
        [$space, $url] = $this->givenLinkedSpace(canUpload: true);

        $this->client->getCookieJar()->clear();
        $this->client->setServerParameter('HTTP_X-Requested-With', '');
        $this->client->request('POST', (string) parse_url($url, PHP_URL_PATH).'/files', [], ['file' => $this->aFile('logo.jpg')]);

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->files->count(['space' => $space]));
    }

    /** The guest policy reads the bytes: an SVG named like a photo is refused with a sentence. */
    public function testAnSvgIsRefusedWithAReason(): void
    {
        [$space, $url] = $this->givenLinkedSpace(canUpload: true);

        $svg = $this->workDirectory.'/innocent.jpg';
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->send($url, new UploadedFile($svg, 'innocent.jpg', 'image/jpeg', null, true));

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame('studio.public.space.errors.upload_type_refused', $this->payload()['errors']['file']);
        self::assertSame(0, $this->files->count(['space' => $space]));
    }

    /**
     * What the client page is handed, for the link behind this address.
     *
     * @return array<string, mixed>
     */
    private function pageProps(string $url): array
    {
        [, , $selector, $token] = explode('/', (string) parse_url($url, PHP_URL_PATH));
        $link = static::getContainer()->get(SpaceAccessLinkManagerInterface::class)->resolveUsable($selector, $token);
        self::assertNotNull($link);

        return static::getContainer()->get(PublicSpaceViewBuilder::class)->view($link, $token);
    }

    private function asGuest(): KernelBrowser
    {
        $this->client->getCookieJar()->clear();
        $this->client->setServerParameter('HTTP_X-Requested-With', 'XMLHttpRequest');

        return $this->client;
    }

    private function send(string $url, UploadedFile $file): void
    {
        $this->asGuest()->request('POST', (string) parse_url($url, PHP_URL_PATH).'/files', [], ['file' => $file]);
    }

    private function aFile(string $name): UploadedFile
    {
        $path = $this->workDirectory.'/'.$name;
        file_put_contents($path, base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
            .'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAA'
            .'AAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==',
            true,
        ));

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    /** @return array{0: CustomerSpace, 1: string} */
    private function givenLinkedSpace(bool $canUpload): array
    {
        $customer = new Customer();
        $customer->setLegalName('Client qui envoie')->setSiret('73282932000074')->setContractualEmail('upload@example.test');
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/suite/studio/spaces/create', [
            'name' => 'Espace du client',
            'customerId' => $customer->getId(),
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $space = $this->entityManager->getRepository(CustomerSpace::class)->find($this->payload()['space']['id']);
        self::assertInstanceOf(CustomerSpace::class, $space);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/issue', $space->getId()), [
            'recipientEmail' => 'camille@societe.test',
            'label' => 'Camille, gérante',
            'canUpload' => $canUpload,
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return [$space, $this->payload()['url']];
    }

    private function givenMember(CustomerSpace $space): void
    {
        $member = new CustomerSpaceMember();
        $member
            ->setSpace($this->entityManager->getReference(CustomerSpace::class, $space->getId()))
            ->setUser($this->entityManager->getReference(User::class, $this->admin->getId()))
            ->setRole(CustomerSpaceMemberRoleEnum::Lead);

        $this->entityManager->persist($member);
        $this->entityManager->flush();
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
