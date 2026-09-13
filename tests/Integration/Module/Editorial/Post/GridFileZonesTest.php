<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Environment;

/**
 * The two zones that name a file rather than a picture: a recording to play
 * and a document to take away.
 *
 * Both ask their question at render rather than on the way in, for the reason
 * the media zone does: a zone configured with a PDF stays configured with it
 * after somebody replaces the file behind it with a spreadsheet, and only the
 * render knows what it is today. So what is worth checking here is what
 * happens when the answer is no - a player pointed at the wrong thing and a
 * draft named in a published page both have to render as nothing, not as a
 * broken control.
 */
final class GridFileZonesTest extends IntegrationTestCase
{
    private GridViewBuilder $gridViewBuilder;

    private Environment $twig;

    private EntityManagerInterface $entityManager;

    /** @var list<int> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->gridViewBuilder = static::getContainer()->get(GridViewBuilder::class);
        $twig = static::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $this->twig = $twig;
    }

    public function testAnAudioZonePlaysTheRecordingItNames(): void
    {
        $id = $this->document('audio/mpeg', 'entretien.mp3', DocumentStatusEnum::Published);

        $html = $this->render('audio', $id, ['caption' => 'Entretien de mars']);

        self::assertStringContainsString('<audio', $html);
        self::assertStringContainsString('entretien.mp3', $html);
        self::assertStringContainsString('type="audio/mpeg"', $html);
        self::assertStringContainsString('Entretien de mars', $html);
    }

    /**
     * `metadata` and not `none`, unlike the film beside it: a recording has no
     * poster to stand in for it, so a player that has fetched nothing shows an
     * unknown duration and a scrub bar nobody can aim.
     */
    public function testAnAudioZoneFetchesTheDurationButNotTheSound(): void
    {
        $html = $this->render('audio', $this->document('audio/mpeg', 'entretien.mp3', DocumentStatusEnum::Published), []);

        self::assertStringContainsString('preload="metadata"', $html);
    }

    public function testAnAudioZonePointedAtSomethingElseDrawsNothing(): void
    {
        $html = $this->render('audio', $this->document('application/pdf', 'plaquette.pdf', DocumentStatusEnum::Published), []);

        self::assertStringNotContainsString('<audio', $html);
    }

    /**
     * The same withholding as a document, and it matters more here. Since
     * `/uploads` began serving only what is published, a draft resolves to the
     * backend address - so without this the zone would draw a player that
     * answers 403 to every visitor, with nothing on the screen to say so.
     */
    public function testAnAudioZoneDoesNotDrawAPlayerForADraft(): void
    {
        $html = $this->render(
            'audio',
            $this->document('audio/mpeg', 'interne.mp3', DocumentStatusEnum::Draft),
            [],
        );

        self::assertStringNotContainsString('<audio', $html);
        self::assertStringNotContainsString('interne.mp3', $html);
    }

    public function testADocumentZoneDrawsACardWithItsFormatAndItsWeight(): void
    {
        $id = $this->document(
            'application/pdf',
            'plaquette.pdf',
            DocumentStatusEnum::Published,
            size: 2 * 1024 * 1024,
        );

        $html = $this->render('document', $id, []);

        self::assertStringContainsString('plaquette.pdf', $html);
        // The extension as the reader will see it in their downloads folder,
        // not the mime type nobody reads.
        self::assertStringContainsString('PDF', $html);
        self::assertStringContainsString('2.0 Mo', $html);
    }

    /** The typed wording wins; the library's own title is the fallback. */
    public function testADocumentZoneSaysWhatTheAuthorTyped(): void
    {
        $id = $this->document('application/pdf', 'plaquette.pdf', DocumentStatusEnum::Published);

        self::assertStringContainsString(
            'Notre plaquette 2026',
            $this->render('document', $id, ['label' => 'Notre plaquette 2026']),
        );

        self::assertStringContainsString(
            'Pièce de la médiathèque',
            $this->render('document', $id, []),
        );
    }

    /**
     * The check that earns this zone its place. A library holds a client's
     * internal papers beside the ones they hand out, and a zone that advertises
     * a draft turns "not published yet" into a link on a public page.
     */
    public function testADraftDocumentIsNotAdvertised(): void
    {
        foreach ([DocumentStatusEnum::Draft, DocumentStatusEnum::Archived] as $status) {
            $html = $this->render(
                'document',
                $this->document('application/pdf', 'interne.pdf', $status),
                [],
            );

            self::assertStringNotContainsString('interne.pdf', $html, $status->value);
            self::assertSame('', mb_trim(strip_tags($html)), $status->value);
        }
    }

    private function document(
        string $mimeType,
        string $fileName,
        DocumentStatusEnum $status = DocumentStatusEnum::Draft,
        ?int $size = null,
    ): int {
        $document = new Document();
        $document->setTitle('Pièce de la médiathèque');
        $document->setMimeType($mimeType);
        $document->setFilePath('ged/2026/09/'.$fileName);
        $document->setOriginalName($fileName);
        $document->setStatus($status);

        if (null !== $size) {
            $document->setSize($size);
        }

        $this->entityManager->persist($document);
        $this->entityManager->flush();

        $id = (int) $document->getId();
        $this->created[] = $id;

        return $id;
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            $document = $this->entityManager->find(Document::class, $id);

            if (null !== $document) {
                $this->entityManager->remove($document);
            }
        }

        $this->entityManager->flush();
        $this->created = [];

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $words
     */
    private function render(string $type, int $mediaId, array $words): string
    {
        $grid = $this->gridViewBuilder->build(
            [
                'enabled' => true,
                'zones' => [['id' => 'z1', 'type' => $type, 'mediaId' => $mediaId]],
            ],
            ['zones' => ['z1' => $words]],
            'fr',
        );

        self::assertNotNull($grid);

        return $this->twig->render(
            'Frontend/themes/default/editorial/post/_grid_zone.html.twig',
            ['zone' => $grid['zones'][0], 'locale' => 'fr'],
        );
    }
}
