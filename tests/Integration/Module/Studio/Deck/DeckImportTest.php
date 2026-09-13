<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Deck;

use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function array_column;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * A written document, in as a deck.
 *
 * The rule under test is one sentence: a heading opens a slide, each block that
 * follows fills one, and several blocks under the same heading make several
 * slides that repeat it. It is worth pinning end to end rather than on the
 * converter alone, because the slides it writes go through `DeckManager` and
 * are whitelisted there - a mapping that produced a slot the layout does not
 * declare would be dropped on the way in and the import would silently lose it.
 */
final class DeckImportTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
    }

    /** @return array<string, mixed> */
    private function import(array $blocks, string $title = 'Depuis un document'): array
    {
        $this->client->jsonRequest('POST', '/backend/studio/decks/import', [
            'title' => $title,
            'blocks' => $blocks,
        ]);

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testAHeadingOpensASlideAndTheListUnderItFillsIt(): void
    {
        $this->signIn();

        $payload = $this->import([
            ['type' => 'header', 'data' => ['text' => 'Ce qui bloque', 'level' => 2]],
            ['type' => 'list', 'data' => ['items' => [['content' => 'Aucun cache'], ['content' => 'Requêtes N+1']]]],
        ]);

        self::assertResponseIsSuccessful();

        $slides = $payload['deck']['slides'];

        self::assertCount(1, $slides);
        self::assertSame('bullets', $slides[0]['layout']);
        self::assertSame('Ce qui bloque', $slides[0]['content']['title']);
        self::assertSame(['Aucun cache', 'Requêtes N+1'], $slides[0]['content']['bullets']);
    }

    /**
     * Several blocks under one heading make several slides, and every one of
     * them carries the heading: a continuation slide with no title reads as
     * orphaned on a wall.
     */
    public function testSeveralBlocksUnderOneHeadingRepeatIt(): void
    {
        $this->signIn();

        $slides = $this->import([
            ['type' => 'header', 'data' => ['text' => 'Les mesures']],
            ['type' => 'list', 'data' => ['items' => ['Un', 'Deux']]],
            ['type' => 'table', 'data' => ['content' => [['Page', 'Avant'], ['Accueil', '2,4 s']]]],
        ])['deck']['slides'];

        self::assertSame(['bullets', 'table'], array_column($slides, 'layout'));
        self::assertSame('Les mesures', $slides[0]['content']['title']);
        self::assertSame('Les mesures', $slides[1]['content']['title']);
        self::assertSame(['Page | Avant', 'Accueil | 2,4 s'], $slides[1]['content']['rows']);
    }

    /** A heading with nothing under it is a divider announcing what follows. */
    public function testAHeadingWithNothingUnderItBecomesASection(): void
    {
        $this->signIn();

        $slides = $this->import([
            ['type' => 'header', 'data' => ['text' => 'Deuxième partie']],
            ['type' => 'header', 'data' => ['text' => 'Les recommandations']],
            ['type' => 'paragraph', 'data' => ['text' => 'Trois chantiers.']],
        ])['deck']['slides'];

        self::assertSame(['section', 'bullets'], array_column($slides, 'layout'));
        self::assertSame('Deuxième partie', $slides[0]['content']['title']);
    }

    /** Consecutive paragraphs are one slide, not one slide each. */
    public function testConsecutiveParagraphsBecomeOneBulletSlide(): void
    {
        $this->signIn();

        $slides = $this->import([
            ['type' => 'header', 'data' => ['text' => 'Constat']],
            ['type' => 'paragraph', 'data' => ['text' => 'Le catalogue met cinq secondes.']],
            ['type' => 'paragraph', 'data' => ['text' => 'Le panier en met trois.']],
        ])['deck']['slides'];

        self::assertCount(1, $slides);
        self::assertSame(
            ['Le catalogue met cinq secondes.', 'Le panier en met trois.'],
            $slides[0]['content']['bullets'],
        );
    }

    /**
     * Editor.js keeps inline formatting as HTML and a slide keeps it as the
     * three marks its renderer understands. Losing it at the door would make
     * the import a downgrade of what somebody wrote.
     */
    public function testBoldSurvivesAndALinkLosesOnlyItsMarkup(): void
    {
        $this->signIn();

        $slides = $this->import([
            ['type' => 'header', 'data' => ['text' => 'Le <b>tunnel</b>']],
            ['type' => 'paragraph', 'data' => ['text' => 'Voir <a href="https://exemple.test">le rapport</a> et <i>ses annexes</i>']],
        ])['deck']['slides'];

        self::assertSame('Le **tunnel**', $slides[0]['content']['title']);
        self::assertSame('Voir le rapport et *ses annexes*', $slides[0]['content']['bullets'][0]);
    }

    /**
     * A quote and a full-page image carry no title slot. The heading above them
     * is not lost for it: it lands in the kicker, which every layout accepts
     * and which is the line above the content that a heading already was.
     */
    public function testAHeadingSurvivesOnALayoutThatHasNoTitle(): void
    {
        $this->signIn();

        $slides = $this->import([
            ['type' => 'header', 'data' => ['text' => 'Ce qu\'ils en disent']],
            ['type' => 'quote', 'data' => ['text' => 'On perd des commandes.', 'caption' => 'Directrice']],
        ])['deck']['slides'];

        self::assertSame('quote', $slides[0]['layout']);
        self::assertSame('Ce qu\'ils en disent', $slides[0]['content']['kicker']);
        self::assertSame('On perd des commandes.', $slides[0]['content']['quote']);
    }

    public function testADocumentWithNothingUsableIsRefused(): void
    {
        $this->signIn();

        $this->import([['type' => 'delimiter', 'data' => []]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testImportingNeedsThePrivilegeToCreate(): void
    {
        $this->client->jsonRequest('POST', '/backend/studio/decks/import', ['title' => 'Sans compte', 'blocks' => []]);

        self::assertResponseRedirects();
    }

    private function signIn(): void
    {
        $admin = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);

        $this->client->loginUser($admin, 'admin');
    }
}
