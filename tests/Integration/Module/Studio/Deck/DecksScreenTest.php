<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Deck;

use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\Deck\Enum\DeckThemeEnum;
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

    /**
     * The presenter's page is behind the back office's door.
     *
     * The notes are the presenter's, and this is the only page that draws them:
     * the public share link strips them from its payload, and the full-screen
     * player never asks for them. A presenter view reachable without signing in
     * would undo all of that in one route.
     */
    public function testThePresenterPageCarriesTheNotesAndNeedsAnAccount(): void
    {
        $container = static::getContainer();
        $deckManager = $container->get(DeckManager::class);

        $deck = $deckManager->create('Comité de pilotage');
        $slide = $deckManager->addSlide($deck, SlideLayoutEnum::Section);
        $slide->setSpeakerNotes('Marquer un temps avant la troisième puce.');
        $container->get(EntityManagerInterface::class)->flush();

        $this->client->request('GET', '/backend/studio/decks/'.$deck->getId().'/presenter');
        self::assertResponseRedirects();

        $this->signIn();
        $this->client->request('GET', '/backend/studio/decks/'.$deck->getId().'/presenter');

        self::assertResponseIsSuccessful();

        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('DeckPresenterApp', $body);
        self::assertStringContainsString('Marquer un temps', $body);
    }

    /**
     * A deck opened from a model takes its shape and its look, and its own
     * filing from the form somebody has just filled.
     *
     * And it is not itself a model: a copy that arrived in the picker as a
     * second template is how a list of three models becomes a list of thirty.
     */
    public function testADeckOpenedFromAModelTakesItsShapeButNotItsFiling(): void
    {
        $this->signIn();

        $container = static::getContainer();
        $deckManager = $container->get(DeckManager::class);

        $model = $deckManager->create('Trame d\'audit');
        $model->setTemplate(true);
        $deckManager->writeAppearance($model, DeckThemeEnum::Ink, ['slideNumbers' => true]);

        foreach (['Constat', 'Recommandations'] as $title) {
            $slide = $deckManager->addSlide($model, SlideLayoutEnum::Section);
            $deckManager->writeContent($slide, ['title' => $title]);
        }

        $container->get(EntityManagerInterface::class)->flush();

        $this->client->jsonRequest('POST', '/backend/studio/decks/create', [
            'title' => 'Audit Dupont',
            'fromTemplateId' => $model->getId(),
        ]);

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Audit Dupont', $payload['deck']['title']);
        self::assertSame(2, $payload['deck']['slideCount']);
        self::assertSame('ink', $payload['deck']['theme']);
        self::assertTrue($payload['deck']['appearance']['slideNumbers']);
        self::assertFalse($payload['deck']['isTemplate'], 'a deck opened from a model is not itself one');
    }

    /**
     * A model that vanished between the page load and the save opens an empty
     * deck rather than losing the title somebody just typed.
     */
    public function testAnUnknownModelOpensAnEmptyDeck(): void
    {
        $this->signIn();

        $this->client->jsonRequest('POST', '/backend/studio/decks/create', [
            'title' => 'Sans modèle',
            'fromTemplateId' => 999999,
        ]);

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $payload['deck']['slideCount']);
    }

    private function signIn(): void
    {
        $admin = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);

        $this->client->loginUser($admin, 'admin');
    }
}
