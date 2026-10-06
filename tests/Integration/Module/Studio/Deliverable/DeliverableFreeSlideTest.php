<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Deliverable;

use Aurora\Module\Dev\Audit\Entity\AuditLog;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Deliverable\Entity\Deliverable;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableFormatEnum;
use Aurora\Module\Studio\Deliverable\Slides\Enum\SlideLayoutEnum;
use Aurora\Module\Studio\Deliverable\Slides\Service\DeckPictures;
use Aurora\Module\Studio\Deliverable\Slides\SlidesManager;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function file_put_contents;
use function json_decode;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;

use const JSON_THROW_ON_ERROR;

/**
 * A presentation's free slide, through the editor's routes.
 *
 * What is worth checking end to end: that a slide written through the save
 * route comes back sanitized and with what is needed to draw it, that the
 * media library counts an image placed on it, and that a file that is not a
 * font is refused.
 */
final class DeliverableFreeSlideTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $admin = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', Deliverable::class))->execute();
        $this->entityManager->createQuery(sprintf("DELETE FROM %s a WHERE a.entityType = 'Deliverable'", AuditLog::class))->execute();

        parent::tearDown();
    }

    public function testAFreeSlideRoundTripsThroughTheUpdateRoute(): void
    {
        [$id, $slideId] = $this->freeSlide();

        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/slides/%d/update', $id, $slideId), [
            'layout' => 'free',
            'content' => [
                'fill' => ['type' => 'linear', 'angle' => 90, 'stops' => [['color' => 'accent', 'at' => 0], ['color' => '#000000', 'at' => 100]]],
                'elements' => [
                    ['id' => 'title', 'type' => 'text', 'x' => 10, 'y' => 10, 'w' => 60, 'h' => 20, 'html' => '<b>Bilan</b><img src=x onerror=alert(1)>', 'mediaUrl' => 'derived'],
                    ['id' => 'film', 'type' => 'embed', 'x' => 50, 'y' => 40, 'w' => 40, 'h' => 40, 'url' => 'https://youtu.be/dQw4w9WgXcQ'],
                ],
            ],
            'speakerNotes' => '',
        ]);

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        [$text, $embed] = $payload['slide']['content']['elements'];

        self::assertSame('<b>Bilan</b>', $text['html']);
        self::assertArrayNotHasKey('mediaUrl', $text);
        self::assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $embed['embedUrl']);
        self::assertSame('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg', $embed['thumbnail']);
        self::assertSame('linear', $payload['slide']['content']['fill']['type']);
    }

    /** An image placed by hand is an image the media library must not believe unused. */
    public function testThePicturesAndFilmsOfAFreeSlideAreCountedAsUsed(): void
    {
        [$id] = $this->freeSlide([
            ['id' => 'a', 'type' => 'image', 'mediaId' => 901],
            ['id' => 'b', 'type' => 'video', 'mediaId' => 902],
        ], ['bgVideoId' => 903]);

        $deliverable = $this->entityManager->find(Deliverable::class, $id);
        self::assertInstanceOf(Deliverable::class, $deliverable);

        $ids = self::getContainer()->get(DeckPictures::class)->idsUsedBy($deliverable);

        self::assertContains(901, $ids);
        self::assertContains(902, $ids);
        self::assertContains(903, $ids);
    }

    public function testAFileThatIsNotAFontIsRefused(): void
    {
        [$id] = $this->freeSlide();

        $path = (string) tempnam(sys_get_temp_dir(), 'font');
        file_put_contents($path, '<?php echo "pas une police";');

        $this->client->request('POST', sprintf('/suite/studio/deliverables/%d/fonts/upload', $id), [], [
            'file' => new UploadedFile($path, 'police.woff2', null, null, true),
        ]);

        self::assertResponseStatusCodeSame(400);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('suite.studio.deliverables.slides.free.font_errors.not_a_font', $payload['error']);
    }

    /**
     * A Studio presentation and a free slide, written by the manager the way
     * the editor writes them.
     *
     * @param list<array<string, mixed>> $elements
     * @param array<string, mixed>       $extra
     *
     * @return array{0: int, 1: int}
     */
    private function freeSlide(array $elements = [], array $extra = []): array
    {
        $slides = self::getContainer()->get(SlidesManager::class);

        $deliverable = new Deliverable(null, 'Diapos libres', 'fr', DeliverableFormatEnum::Slides);
        $this->entityManager->persist($deliverable);

        $slide = $slides->addSlide($deliverable, SlideLayoutEnum::Free);
        $slides->writeContent($slide, ['elements' => $elements, ...$extra]);
        $this->entityManager->flush();

        return [(int) $deliverable->getId(), (int) $slide->getId()];
    }
}
