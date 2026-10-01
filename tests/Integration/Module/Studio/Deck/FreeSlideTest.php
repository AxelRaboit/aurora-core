<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Deck;

use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Deck\Entity\Deck;
use Aurora\Module\Studio\Deck\Enum\SlideLayoutEnum;
use Aurora\Module\Studio\Deck\Manager\DeckManager;
use Aurora\Module\Studio\Deck\Service\DeckPictures;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function copy;
use function dirname;
use function file_put_contents;
use function json_decode;
use function sys_get_temp_dir;
use function tempnam;

use const JSON_THROW_ON_ERROR;

/**
 * The free slide, through the routes the editor and a share link use.
 *
 * What is worth pinning end to end: that a slide written through the update
 * route comes back cleaned and with what it needs to be drawn, that the
 * library counts a picture placed on it, and that an uploaded font is refused
 * when it is not one and served from the application when it is.
 */
final class FreeSlideTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
    }

    public function testAFreeSlideRoundTripsThroughTheUpdateRoute(): void
    {
        $this->signIn();
        [$deckId, $slideId] = $this->freeSlide();

        $this->client->jsonRequest('POST', sprintf('/backend/studio/decks/%d/slides/%d/update', $deckId, $slideId), [
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

    /** A picture placed by hand is a picture the library must not call unused. */
    public function testThePicturesAndFilmsOfAFreeSlideAreCountedAsUsed(): void
    {
        [$deckId] = $this->freeSlide([
            ['id' => 'a', 'type' => 'image', 'mediaId' => 901],
            ['id' => 'b', 'type' => 'video', 'mediaId' => 902],
        ], ['bgVideoId' => 903]);

        $deck = static::getContainer()->get(EntityManagerInterface::class)
            ->find(Deck::class, $deckId);

        $ids = static::getContainer()->get(DeckPictures::class)->idsUsedBy($deck);

        self::assertContains(901, $ids);
        self::assertContains(902, $ids);
        self::assertContains(903, $ids);
    }

    public function testAFileThatIsNotAFontIsRefused(): void
    {
        $this->signIn();

        $path = tempnam(sys_get_temp_dir(), 'font');
        file_put_contents($path, '<?php echo "pas une police";');

        $this->client->request('POST', '/backend/studio/decks/fonts/upload', [], [
            'file' => new UploadedFile($path, 'police.woff2', null, null, true),
        ]);

        self::assertResponseStatusCodeSame(400);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('backend.studio.decks.free.font_errors.not_a_font', $payload['error']);
    }

    /**
     * Uploaded, listed, and served without an account - a share link is read
     * by somebody who has none - with a font's own type.
     */
    public function testAFontIsUploadedListedAndServedFromTheApplication(): void
    {
        $this->signIn();

        $source = dirname(__DIR__, 5).'/node_modules/@fontsource/lobster/files/lobster-latin-400-normal.woff2';
        $path = tempnam(sys_get_temp_dir(), 'font');
        copy($source, $path);

        $this->client->request('POST', '/backend/studio/decks/fonts/upload', [], [
            'file' => new UploadedFile($path, 'Marque Display.woff2', null, null, true),
        ]);

        self::assertResponseIsSuccessful();

        $font = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['font'];

        self::assertSame('Marque Display', $font['name']);
        self::assertMatchesRegularExpression('/^upload-\d+$/', $font['key']);

        $this->client->request('GET', '/backend/studio/decks/fonts');
        $listed = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['fonts'];

        self::assertContains($font['key'], array_column($listed, 'key'));

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $font['url']);

        self::assertResponseIsSuccessful();
        self::assertSame('font/woff2', $this->client->getResponse()->headers->get('Content-Type'));
    }

    /**
     * @param list<array<string, mixed>> $elements
     * @param array<string, mixed>       $extra
     *
     * @return array{0: int, 1: int}
     */
    private function freeSlide(array $elements = [], array $extra = []): array
    {
        $decks = static::getContainer()->get(DeckManager::class);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $deck = $decks->create('Diapos libres');
        $slide = $decks->addSlide($deck, SlideLayoutEnum::Free);
        $decks->writeContent($slide, ['elements' => $elements, ...$extra]);
        $entityManager->flush();

        return [(int) $deck->getId(), (int) $slide->getId()];
    }

    private function signIn(): void
    {
        $admin = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);

        $this->client->loginUser($admin, 'admin');
    }
}
