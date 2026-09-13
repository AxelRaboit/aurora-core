<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Controller;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function sprintf;
use function uniqid;

/**
 * Who may read a file through `/uploads/{path}`, and through the permalink
 * that redirects to it.
 *
 * These are integration tests rather than unit tests on purpose. The rule
 * being checked is not "the guard returns Denied" - that is a method call and
 * proves nothing - but that a visitor with no session gets a 404 from the
 * real router, through the real `access_control`, whose last line is
 * `{ path: ^/, roles: PUBLIC_ACCESS }` and will still be tomorrow. Anything
 * that stops short of a request would keep passing on the day the guard stops
 * being wired.
 *
 * Every document here is written with its bytes on disk, because a missing
 * file is a 404 too: a test that asserted 404 without them would pass whether
 * or not anything was ever withheld.
 */
final class UploadsServeAccessTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private UrlGeneratorInterface $urlGenerator;

    private Filesystem $filesystem;

    private string $uploadDir;

    /** @var list<string> */
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->urlGenerator = $container->get(UrlGeneratorInterface::class);
        $this->uploadDir = (string) $container->getParameter('app.upload_dir');
        $this->filesystem = new Filesystem();
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $absolute) {
            $this->filesystem->remove($absolute);
        }

        $this->written = [];

        parent::tearDown();
    }

    // ── The rule ─────────────────────────────────────────────────────────

    public function testAPublishedDocumentIsServedToAVisitorWithNoSession(): void
    {
        $document = $this->document(DocumentStatusEnum::Published);

        $this->assertServed((string) $document->getFilePath());
    }

    public function testADraftDocumentIsNotServedToAVisitorWithNoSession(): void
    {
        $document = $this->document(DocumentStatusEnum::Draft);

        $this->assertWithheld((string) $document->getFilePath());
    }

    /**
     * An archived document has been withdrawn, and the public library has
     * always listed `published` alone. Serving its file would publish it
     * again through the back door.
     */
    public function testAnArchivedDocumentIsWithheldToo(): void
    {
        $document = $this->document(DocumentStatusEnum::Archived);

        $this->assertWithheld((string) $document->getFilePath());
    }

    // ── Derived files ────────────────────────────────────────────────────
    //
    // The interesting half. A variant and a rendered thumbnail have their own
    // keys, in their own directories, and neither is the path the row was
    // found by - so a check written against `filePath` alone would withhold a
    // picture while handing out a legible copy of it.

    public function testTheResponsiveVariantOfADraftIsWithheld(): void
    {
        $document = $this->document(DocumentStatusEnum::Draft);

        $this->assertWithheld($document->getVariants()['medium']);
    }

    public function testTheResponsiveVariantOfAPublishedDocumentIsServed(): void
    {
        $document = $this->document(DocumentStatusEnum::Published);

        $this->assertServed($document->getVariants()['medium']);
    }

    public function testTheRenderedThumbnailOfADraftIsWithheld(): void
    {
        $document = $this->document(DocumentStatusEnum::Draft);

        $this->assertWithheld((string) $document->getThumbnailPath());
    }

    public function testTheRenderedThumbnailOfAPublishedDocumentIsServed(): void
    {
        $document = $this->document(DocumentStatusEnum::Published);

        $this->assertServed((string) $document->getThumbnailPath());
    }

    // ── Files no living row claims ───────────────────────────────────────

    public function testAKeyNoDocumentClaimsIsWithheld(): void
    {
        $path = sprintf('ged/1999/09/orphan-%s.png', uniqid());
        $this->write($path);

        // An orphan left by a deletion, or the snapshot a previous version
        // still points at. Nothing public links to either.
        $this->assertWithheld($path);
    }

    public function testADocumentInTheBinIsWithheldEvenThoughItSaysPublished(): void
    {
        $document = $this->document(DocumentStatusEnum::Published);
        $document->setDeletedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->assertWithheld((string) $document->getFilePath());
    }

    // ── Staff ────────────────────────────────────────────────────────────

    public function testStaffReadADraftThroughTheGatedRoute(): void
    {
        $document = $this->document(DocumentStatusEnum::Draft);
        $this->login();

        self::assertSame(200, $this->requestGated((string) $document->getFilePath()));
    }

    /**
     * The half that is easy to get wrong. `/uploads/…` is not matched by the
     * admin firewall (`^/(backend|dev)`), so a backend session is never
     * restored there: staff are refused by the public endpoint exactly as
     * strangers are, and the gated route is not a convenience but the only
     * address that can work.
     */
    public function testTheGatedRouteIsTheOnlyOneThatAnswersForStaff(): void
    {
        $document = $this->document(DocumentStatusEnum::Draft);
        $this->login();

        self::assertSame(404, $this->request((string) $document->getFilePath()));
    }

    public function testAVisitorCannotUseTheGatedRouteEither(): void
    {
        $document = $this->document(DocumentStatusEnum::Draft);

        // No session: the admin firewall answers before the controller does.
        self::assertNotSame(200, $this->requestGated((string) $document->getFilePath()));
    }

    /**
     * Served, but not left anywhere. The public answer is
     * `public, max-age=86400, immutable`, which is right for a file anybody
     * may read and wrong for one this visitor may read.
     */
    public function testTheCopyStaffReceiveIsNotPubliclyCacheable(): void
    {
        $document = $this->document(DocumentStatusEnum::Draft);
        $this->login();

        $this->requestGated((string) $document->getFilePath());
        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');

        self::assertStringContainsString('private', $cacheControl);
        self::assertStringNotContainsString('immutable', $cacheControl);
    }

    /**
     * The privilege that opens the GED must not open the area next door.
     * Without the prefix check in the controller it would, and the contracts
     * module's own authorisation would be back to being optional.
     */
    public function testTheGatedRouteRefusesTheContractsAreaToStaffToo(): void
    {
        $path = 'contracts/2026/CM-2026-0002.pdf';
        $this->write($path);
        $this->login();

        self::assertSame(404, $this->requestGated($path));
    }

    public function testAPublishedDocumentStaysPubliclyCacheable(): void
    {
        $document = $this->document(DocumentStatusEnum::Published);

        $this->request((string) $document->getFilePath());

        self::assertStringContainsString(
            'immutable',
            (string) $this->client->getResponse()->headers->get('Cache-Control'),
            'an image embedded in a public page must stay cacheable by everything in between',
        );
    }

    // ── The permalink ────────────────────────────────────────────────────

    public function testThePermalinkOfADraftIsNotFoundForAVisitor(): void
    {
        $document = $this->document(DocumentStatusEnum::Draft);

        $this->client->request(
            HttpMethodEnum::Get->value,
            $this->urlGenerator->generate('ged_document_view', ['id' => $document->getId()]),
        );

        // Not a redirect that then fails: the id is a sequence, and a 302
        // would confirm both the file's path and that the id names something.
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testThePermalinkOfAPublishedDocumentStillRedirects(): void
    {
        $document = $this->document(DocumentStatusEnum::Published);

        $this->client->request(
            HttpMethodEnum::Get->value,
            $this->urlGenerator->generate('ged_document_view', ['id' => $document->getId()]),
        );

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
    }

    // ── Contracts ────────────────────────────────────────────────────────

    /**
     * The reference is sequential, so this path is guessable by construction.
     * Withheld from staff as well: the module has its own route that streams
     * the signed file with the right authorisation, and a second way in is
     * how the first one gets forgotten.
     */
    public function testASignedContractIsNeverServedByTheCatchAll(): void
    {
        $path = 'contracts/2026/CM-2026-0001.pdf';
        $this->write($path);

        $this->assertWithheld($path);

        $this->login();
        self::assertSame(404, $this->request($path));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function document(DocumentStatusEnum $status): Document
    {
        $stem = 'photo-'.uniqid();
        $filePath = sprintf('ged/1999/03/%s.png', $stem);
        $thumbnailPath = sprintf('ged/thumbnails/1999/03/%s.png', $stem);
        // The key ImageVariantGenerator writes: the source's own directory,
        // plus `variants/<size>/`, re-encoded to WebP.
        $variantPath = sprintf('ged/1999/03/variants/medium/%s.webp', $stem);

        $this->write($filePath);
        $this->write($thumbnailPath);
        $this->write($variantPath);

        $document = new Document();
        $document->setTitle('Pièce '.$stem)
            ->setStatus($status)
            ->setFilePath($filePath)
            ->setThumbnailPath($thumbnailPath)
            ->setVariants(['medium' => $variantPath])
            ->setMimeType('image/png')
            ->setSize(18);

        $this->entityManager->persist($document);
        $this->entityManager->flush();

        return $document;
    }

    private function write(string $relativePath): void
    {
        $absolute = Path::join($this->uploadDir, $relativePath);
        $this->filesystem->mkdir(\dirname($absolute));
        file_put_contents($absolute, 'hello-from-uploads');

        $this->written[] = $absolute;
    }

    private function request(string $relativePath): int
    {
        // BinaryFileResponse and StreamedResponse both write to the output
        // buffer as they are sent.
        ob_start();
        $this->client->request(
            HttpMethodEnum::Get->value,
            $this->urlGenerator->generate('uploads_serve', ['path' => $relativePath]),
        );
        ob_end_clean();

        return $this->client->getResponse()->getStatusCode();
    }

    private function requestGated(string $relativePath): int
    {
        ob_start();
        $this->client->request(
            HttpMethodEnum::Get->value,
            $this->urlGenerator->generate('backend_ged_files', ['path' => $relativePath]),
        );
        ob_end_clean();

        return $this->client->getResponse()->getStatusCode();
    }

    private function assertServed(string $relativePath): void
    {
        self::assertSame(200, $this->request($relativePath), sprintf('%s should be readable without a session', $relativePath));
    }

    private function assertWithheld(string $relativePath): void
    {
        self::assertSame(404, $this->request($relativePath), sprintf('%s should not be readable without a session', $relativePath));
    }

    private function login(): void
    {
        $user = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);

        self::assertInstanceOf(CoreUserInterface::class, $user);

        $this->client->loginUser($user, 'admin');
    }
}
