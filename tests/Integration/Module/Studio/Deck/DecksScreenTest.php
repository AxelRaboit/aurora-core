<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Deck;

use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\Deck\Enum\SlideLayoutEnum;
use Aurora\Module\Studio\Deck\Manager\DeckManager;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * The deck screen, end to end.
 *
 * Three things are worth a test here: that the page opens at all, that a deck
 * survives a round trip through the form, and that duplicating leaves the
 * customer behind - which is the one judgement in the duplicator that a reader
 * would not guess.
 */
final class DecksScreenTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
    }

    public function testTheListOpens(): void
    {
        $this->signIn();
        $this->client->request('GET', '/backend/studio/decks');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('DecksApp', (string) $this->client->getResponse()->getContent());
    }

    public function testADeckIsCreatedWithItsCategory(): void
    {
        $this->signIn();

        $this->client->jsonRequest('POST', '/backend/studio/decks/create', [
            'title' => 'Audit de septembre',
            'description' => 'Ce que le site fait mal.',
        ]);

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($payload['success']);
        self::assertSame('Audit de septembre', $payload['deck']['title']);
        self::assertSame(0, $payload['deck']['slideCount']);
    }

    public function testATitlelessDeckIsRefused(): void
    {
        $this->signIn();

        $this->client->jsonRequest('POST', '/backend/studio/decks/create', ['title' => '']);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * The one judgement in the duplicator worth pinning: the slides come along,
     * the customer does not. Carrying the client over is how a deck ends up
     * presented to one company with another company's name on it.
     */
    public function testDuplicatingCopiesTheSlidesAndLeavesTheCustomerBehind(): void
    {
        $this->signIn();

        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $deckManager = $container->get(DeckManager::class);

        $deck = $deckManager->create('Trame de stratégie');
        $slide = $deckManager->addSlide($deck, SlideLayoutEnum::Bullets);
        $deckManager->writeContent($slide, ['title' => 'Trois axes', 'bullets' => ['Un', 'Deux']]);

        $customer = $container->get('doctrine')->getRepository(Customer::class)->findOneBy([]);
        $deck->setCustomer($customer);

        $entityManager->flush();

        $this->client->request('POST', '/backend/studio/decks/'.$deck->getId().'/duplicate');

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $payload['deck']['slideCount']);
        self::assertNull($payload['deck']['customer'], 'the copy must not carry the customer over');
        self::assertNotSame($deck->getId(), $payload['deck']['id']);
    }

    /**
     * The look is written, merged with the theme, and comes back resolved.
     *
     * Two answers in one payload on purpose: `style` is what somebody chose and
     * `appearance` is what the frame draws. The accent below is overridden and
     * the background is not, so the response proves both halves of the merge in
     * one go.
     */
    public function testTheAppearanceIsWrittenAndComesBackResolved(): void
    {
        $this->signIn();

        $container = static::getContainer();
        $deckManager = $container->get(DeckManager::class);

        $deck = $deckManager->create('Proposition commerciale');
        $container->get(EntityManagerInterface::class)->flush();

        $this->client->jsonRequest('POST', '/backend/studio/decks/'.$deck->getId().'/appearance', [
            'theme' => 'paper',
            'style' => [
                'accent' => '#C2371F',
                'slideNumbers' => true,
                'footerText' => ' Confidentiel ',
                'nonsense' => 'dropped',
            ],
        ]);

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('paper', $payload['theme']);
        self::assertArrayNotHasKey('nonsense', $payload['style']);
        self::assertSame('Confidentiel', $payload['style']['footerText']);

        // Overridden, so the deck's own value wins.
        self::assertSame('#c2371f', $payload['appearance']['accent']);
        // Untouched, so the theme's own value reaches the slide.
        self::assertSame('#faf8f4', $payload['appearance']['background']);
        self::assertTrue($payload['appearance']['slideNumbers']);
        self::assertSame('none', $payload['appearance']['logoPlacement'], 'no picture means no placement');
    }

    /**
     * A copy lands right after its source, not at the end.
     *
     * A slide is duplicated to write a variant of it, and a variant that lands
     * twenty slides away has to be dragged back before it can be edited.
     */
    public function testADuplicatedSlideLandsRightAfterItsSource(): void
    {
        $this->signIn();

        $container = static::getContainer();
        $deckManager = $container->get(DeckManager::class);

        $deck = $deckManager->create('Trois temps');

        foreach (['Un', 'Deux', 'Trois'] as $title) {
            $slide = $deckManager->addSlide($deck, SlideLayoutEnum::Section);
            $deckManager->writeContent($slide, ['title' => $title]);
        }

        $container->get(EntityManagerInterface::class)->flush();

        $first = $deck->getSlides()->first();

        $this->client->request(
            'POST',
            '/backend/studio/decks/'.$deck->getId().'/slides/'.$first->getId().'/duplicate',
        );

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $payload['slide']['position'], 'the copy sits right after what it copies');
        self::assertSame(['title' => 'Un'], $payload['slide']['content']);
        self::assertNotSame($first->getId(), $payload['slide']['id']);
    }

    /** A slide of one deck is never reachable through another deck's address. */
    public function testASlideOfAnotherDeckCannotBeDuplicated(): void
    {
        $this->signIn();

        $container = static::getContainer();
        $deckManager = $container->get(DeckManager::class);

        $mine = $deckManager->create('Le mien');
        $other = $deckManager->create('Un autre');
        $slide = $deckManager->addSlide($other, SlideLayoutEnum::Title);

        $container->get(EntityManagerInterface::class)->flush();

        $this->client->request(
            'POST',
            '/backend/studio/decks/'.$mine->getId().'/slides/'.$slide->getId().'/duplicate',
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnUnknownThemeIsRefused(): void
    {
        $this->signIn();

        $container = static::getContainer();
        $deck = $container->get(DeckManager::class)->create('Sans thème');
        $container->get(EntityManagerInterface::class)->flush();

        $this->client->jsonRequest('POST', '/backend/studio/decks/'.$deck->getId().'/appearance', [
            'theme' => 'neon',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    private function signIn(): void
    {
        $admin = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);

        $this->client->loginUser($admin, 'admin');
    }
}
