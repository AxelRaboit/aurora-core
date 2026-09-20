<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\CustomerSpace;

use Aurora\Core\Storage\Access\UploadPolicyProvider;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLink;
use Aurora\Module\Studio\SpaceAccess\Manager\SpaceAccessLinkManagerInterface;
use Aurora\Module\Studio\SpaceAccess\Repository\SpaceAccessLinkRepository;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentAttachment;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumn;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItem;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentAttachmentRepository;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentColumnRepository;
use Aurora\Tests\Integration\Concern\ResetsRateLimiters;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function basename;
use function dirname;
use function explode;
use function json_decode;
use function parse_url;
use function sprintf;

use const PHP_URL_PATH;

/**
 * What a client holding a link can send, and everything that stops them.
 *
 * Three walls, tested one at a time because they fail differently: the right on
 * the link answers like a stranger, the policy answers with a sentence, and the
 * space check answers like a stranger again.
 */
final class SpaceGuestUploadTest extends IntegrationTestCase
{
    use ResetsRateLimiters;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private SpaceContentColumnRepository $columns;

    private SpaceContentAttachmentRepository $attachments;

    private SpaceAccessLinkRepository $links;

    private SpaceAccessLinkManagerInterface $linkManager;

    private DocumentRepository $documents;

    private string $workDir;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();

        $container = static::getContainer();

        $admin = $container->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);
        $this->admin = $admin;
        $this->client->loginUser($admin, 'admin');

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->columns = $container->get(SpaceContentColumnRepository::class);
        $this->attachments = $container->get(SpaceContentAttachmentRepository::class);
        $this->links = $container->get(SpaceAccessLinkRepository::class);
        $this->linkManager = $container->get(SpaceAccessLinkManagerInterface::class);
        $this->documents = $container->get(DocumentRepository::class);

        // The upload limiter allows twenty an hour per address and its
        // counters outlive the process, so a class that uploads once per test
        // goes red on its third run of the hour - a 429 answered by a route
        // the test never meant to exercise.
        $this->resetRateLimiter('space_guest_upload');

        $this->workDir = sys_get_temp_dir().'/aurora-guest-upload-'.bin2hex(random_bytes(4));
        mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir.'/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->workDir)) {
            rmdir($this->workDir);
        }

        foreach ([
            SpaceContentAttachment::class,
            SpaceContentItem::class,
            SpaceContentColumn::class,
            SpaceAccessLink::class,
            CustomerSpace::class,
            Customer::class,
        ] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        parent::tearDown();
    }

    /**
     * The right is off unless somebody turned it on.
     *
     * The column defaults false where the other two default true, so a link
     * issued without saying anything about files cannot send one - and it is
     * refused the way a stranger is, because saying "you may read but not send"
     * tells whoever holds a leaked address exactly what they hold.
     */
    public function testALinkIssuedWithoutTheRightCannotSend(): void
    {
        [$space, $url] = $this->givenLinkedSpace(canUpload: false);
        $item = $this->givenItem($space);

        $this->upload($url, $item['id'], $this->aFile('photo.jpg'));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->attachments->count([]));
    }

    public function testALinkWithTheRightSendsAFileAndItIsSignedAsTheClient(): void
    {
        [$space, $url] = $this->givenLinkedSpace(canUpload: true);
        $item = $this->givenItem($space);

        $this->upload($url, $item['id'], $this->aFile('photo.jpg'));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $files = $this->payload()['attachments'][$item['id']];
        self::assertCount(1, $files);
        self::assertTrue($files[0]['fromClient']);
        // The address the link was sent to: there is no account behind a link,
        // so the mailbox is the only name there is.
        self::assertSame('Camille, gérante', $files[0]['author']);
    }

    /**
     * An SVG is refused, and this is the test the policy exists for.
     *
     * It is a document that can carry script and it would come back from the
     * application's own origin. The extension says `.jpg` here on purpose: what
     * the browser calls a file is written by whoever is uploading, so the
     * refusal has to come from the bytes.
     */
    public function testAnSvgIsRefusedEvenWhenItClaimsToBeAPhoto(): void
    {
        [$space, $url] = $this->givenLinkedSpace(canUpload: true);
        $item = $this->givenItem($space);

        $svg = $this->workDir.'/innocent.jpg';
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->upload($url, $item['id'], new UploadedFile($svg, 'innocent.jpg', 'image/jpeg', null, true));

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame(
            'studio.public.space.errors.upload_type_refused',
            $this->payload()['errors']['file'],
        );
        self::assertSame(0, $this->attachments->count([]));
    }

    /**
     * Refused with a sentence rather than a 404.
     *
     * By this point the caller has proved they hold a usable link, and "that
     * file is too big" is what they need to read in order to send a smaller
     * one. The two refusals are separate keys for the same reason: they send
     * somebody to two different fixes.
     */
    public function testAFileOverTheCeilingIsRefusedWithAReason(): void
    {
        [$space, $url] = $this->givenLinkedSpace(canUpload: true);
        $item = $this->givenItem($space);

        $big = $this->workDir.'/big.jpg';
        file_put_contents($big, $this->jpegBytes().str_repeat("\0", static::getContainer()->get(UploadPolicyProvider::class)->forSpaceGuests()->maxBytes));

        $this->upload($url, $item['id'], new UploadedFile($big, 'big.jpg', 'image/jpeg', null, true));

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame(
            'studio.public.space.errors.upload_too_large',
            $this->payload()['errors']['file'],
        );
    }

    /**
     * A link cannot hang a file off another space's card.
     *
     * Answered like a stranger: the card id is a number anybody can change.
     */
    public function testACardOfAnotherSpaceIsRefused(): void
    {
        [, $url] = $this->givenLinkedSpace(canUpload: true);
        $theirs = $this->givenSpace('Autre client', '39860733300060');
        $item = $this->givenItem($theirs);

        $this->upload($url, $item['id'], $this->aFile('photo.jpg'));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->attachments->count([]));
    }

    /**
     * The test this whole change exists for.
     *
     * A file on a card used to be a *published* GED document, which the public
     * catch-all serves to anybody holding its address with no session at all -
     * so revoking a client's link left every visual on their board readable at
     * a fixed URL, for ever. Files are drafts now, and the client reads them
     * through a route that resolves the link first.
     */
    public function testRevokingTheLinkStopsItsFilesFromBeingReadable(): void
    {
        [$space, $url] = $this->givenLinkedSpace(canUpload: true);
        $item = $this->givenItem($space);

        $this->upload($url, $item['id'], $this->aFile('photo.jpg'));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $fileUrl = $this->payload()['attachments'][$item['id']][0]['url'];

        // The address the client's page is given goes through their link, not
        // through GED's public catch-all.
        self::assertStringContainsString('/spaces/', $fileUrl);
        self::assertStringNotContainsString('/uploads/', $fileUrl);

        $this->asGuest()->request('GET', $fileUrl);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $link = $this->links->findOneBy(['space' => $space]);
        self::assertInstanceOf(SpaceAccessLink::class, $link);
        $this->linkManager->revoke($link);

        $this->asGuest()->request('GET', $fileUrl);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /**
     * And the bytes are not reachable round the side either.
     *
     * The draft status is what closes `/uploads/`; without it the route above
     * would be a formality anybody could step past by addressing the file
     * directly.
     */
    public function testTheFileIsNotServedByThePublicCatchAll(): void
    {
        [$space, $url] = $this->givenLinkedSpace(canUpload: true);
        $item = $this->givenItem($space);

        $this->upload($url, $item['id'], $this->aFile('photo.jpg'));

        $document = $this->documents->findOneBy([], ['id' => 'DESC']);
        self::assertInstanceOf(Document::class, $document);
        self::assertSame(DocumentStatusEnum::Draft, $document->getStatus());

        $this->asGuest()->request('GET', '/uploads/'.$document->getFilePath());
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /**
     * One client's link cannot read another client's file.
     */
    public function testALinkCannotReadAnotherSpacesFile(): void
    {
        [$mine, $myUrl] = $this->givenLinkedSpace(canUpload: true);
        $myItem = $this->givenItem($mine);
        $this->upload($myUrl, $myItem['id'], $this->aFile('photo.jpg'));
        $myFileUrl = $this->payload()['attachments'][$myItem['id']][0]['url'];

        // Back to an account: the upload above emptied the cookie jar, which
        // is how a guest request proves it needs no session.
        $this->asStudio();

        $theirs = $this->givenSpace('Autre client', '39860733300060');
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/issue', $theirs->getId()), [
            'recipientEmail' => 'voisin@societe.test',
            'label' => 'Le voisin',
            'canUpload' => true,
        ]);
        $theirToken = (string) parse_url($this->payload()['url'], PHP_URL_PATH);

        // Their selector and token, my attachment id.
        [, , $selector, $token] = explode('/', $theirToken);
        $attachmentId = (int) basename(dirname($myFileUrl));

        $this->asGuest()->request('GET', sprintf('/spaces/%s/%s/attachments/%d/file', $selector, $token, $attachmentId));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    private function asGuest(): KernelBrowser
    {
        $this->client->getCookieJar()->clear();

        return $this->client;
    }

    /**
     * Back to the studio after a guest request.
     *
     * `asGuest()` and `upload()` both empty the cookie jar, which is the only
     * honest way to prove a link works without a session - and it means the
     * next call that needs an account has to say so.
     */
    private function asStudio(): KernelBrowser
    {
        $this->client->loginUser($this->admin, 'admin');

        return $this->client;
    }

    private function upload(string $url, int $itemId, UploadedFile $file): void
    {
        $this->client->getCookieJar()->clear();

        $path = (string) parse_url($url, PHP_URL_PATH);

        $this->client->request(
            'POST',
            sprintf('%s/content/%d/attachments', $path, $itemId),
            [],
            ['file' => $file],
        );
    }

    private function aFile(string $name): UploadedFile
    {
        $path = $this->workDir.'/'.$name;
        file_put_contents($path, $this->jpegBytes());

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    /** The smallest thing the mime guesser calls a JPEG. */
    private function jpegBytes(): string
    {
        return base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
            .'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAA'
            .'AAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==',
            true,
        );
    }

    /** @return array{0: CustomerSpace, 1: string} */
    private function givenLinkedSpace(bool $canUpload): array
    {
        $space = $this->givenSpace();

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/issue', $space->getId()), [
            'recipientEmail' => 'camille@societe.test',
            'label' => 'Camille, gérante',
            'canUpload' => $canUpload,
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return [$space, $this->payload()['url']];
    }

    private function givenSpace(
        string $customerName = 'Client qui envoie',
        string $siret = '73282932000074',
    ): CustomerSpace {
        $customer = new Customer();
        $customer
            ->setLegalName($customerName)
            ->setSiret($siret)
            ->setContractualEmail('upload@example.test');

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
    private function givenItem(CustomerSpace $space): array
    {
        $column = $this->columns->findForSpace($space)[0];

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/create', $space->getId()), [
            'title' => 'Un contenu à illustrer',
            'columnId' => $column->getId(),
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return $this->payload()['items'][0];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
