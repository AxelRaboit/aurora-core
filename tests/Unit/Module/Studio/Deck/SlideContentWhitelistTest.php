<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Studio\Deck;

use Aurora\Module\Studio\Deck\Entity\Slide;
use Aurora\Module\Studio\Deck\Enum\SlideLayoutEnum;
use Aurora\Module\Studio\Deck\Manager\DeckManager;
use Aurora\Module\Studio\Deck\Service\DeckStyleNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

use function sprintf;

/**
 * What a slide keeps out of what a form sends.
 *
 * The content is JSON, so nothing in the database schema says what may be in
 * it. The layout does, and this is the test that the layout is actually
 * consulted rather than merely documented.
 */
final class SlideContentWhitelistTest extends TestCase
{
    public function testItKeepsOnlyTheSlotsTheLayoutDeclares(): void
    {
        $slide = (new Slide())->setLayout(SlideLayoutEnum::Quote);

        $this->manager()->writeContent($slide, [
            'quote' => 'On ne subit pas l avenir, on le fait.',
            'attribution' => 'Georges Bernanos',
            'title' => 'un slot que ce gabarit ne porte pas',
        ]);

        self::assertSame(
            ['quote' => 'On ne subit pas l avenir, on le fait.', 'attribution' => 'Georges Bernanos'],
            $slide->getContent(),
        );
    }

    public function testTheInversionIsStoredOnlyWhenItIsTrue(): void
    {
        $manager = $this->manager();

        $on = (new Slide())->setLayout(SlideLayoutEnum::Section);
        $manager->writeContent($on, ['title' => 'Deuxième partie', 'inverted' => true]);
        self::assertSame(['title' => 'Deuxième partie', 'inverted' => true], $on->getContent());

        // A checkbox posting "on", or a false, both mean the usual way round,
        // which the absence of the key already spells.
        $off = (new Slide())->setLayout(SlideLayoutEnum::Section);
        $manager->writeContent($off, ['title' => 'Deuxième partie', 'inverted' => 'on']);
        self::assertSame(['title' => 'Deuxième partie'], $off->getContent());

        $plain = (new Slide())->setLayout(SlideLayoutEnum::Section);
        $manager->writeContent($plain, ['title' => 'Deuxième partie', 'inverted' => false]);
        self::assertSame(['title' => 'Deuxième partie'], $plain->getContent());
    }

    /** A picture is an id, and a string there would fail to resolve at render. */
    public function testItRefusesAMediaIdThatIsNotAnInteger(): void
    {
        $slide = (new Slide())->setLayout(SlideLayoutEnum::Image);

        $this->manager()->writeContent($slide, ['mediaId' => '42', 'caption' => 'La façade']);

        self::assertSame(['caption' => 'La façade'], $slide->getContent());
    }

    /**
     * The shape lands on a class the frame matches on, so an unknown one draws
     * nothing at all rather than drawing the wrong thing.
     */
    public function testItRefusesAShapeTheFrameCannotDraw(): void
    {
        $manager = $this->manager();

        $known = (new Slide())->setLayout(SlideLayoutEnum::Image);
        $manager->writeContent($known, ['mediaId' => 7, 'mediaShape' => 'arch']);
        self::assertSame(['mediaId' => 7, 'mediaShape' => 'arch'], $known->getContent());

        $unknown = (new Slide())->setLayout(SlideLayoutEnum::Image);
        $manager->writeContent($unknown, ['mediaId' => 7, 'mediaShape' => 'hexagon']);
        self::assertSame(['mediaId' => 7], $unknown->getContent());
    }

    public function testItKeepsBulletsAsAListOfStrings(): void
    {
        $slide = (new Slide())->setLayout(SlideLayoutEnum::Bullets);

        $this->manager()->writeContent($slide, [
            'title' => 'Ce qui bloque',
            'bullets' => ['Le temps de réponse', 12, 'La sauvegarde'],
        ]);

        self::assertSame(
            ['title' => 'Ce qui bloque', 'bullets' => ['Le temps de réponse', 'La sauvegarde']],
            $slide->getContent(),
        );
    }

    /**
     * The list slots are declared once and consulted everywhere. A slot that
     * holds lines but is not named in `listSlots()` would be stored as the raw
     * string a textarea posts, and drawn as nothing.
     */
    public function testEveryListSlotIsDeclaredOnSomeLayout(): void
    {
        $declared = [];

        foreach (SlideLayoutEnum::cases() as $layout) {
            $declared = [...$declared, ...$layout->slots()];
        }

        foreach (SlideLayoutEnum::listSlots() as $slot) {
            self::assertContains($slot, $declared, sprintf('list slot "%s" belongs to no layout', $slot));
        }
    }

    public function testAListSlotKeepsItsLinesAndDropsWhatIsNotOne(): void
    {
        $slide = (new Slide())->setLayout(SlideLayoutEnum::Table);

        $this->manager()->writeContent($slide, [
            'title' => 'Temps de réponse',
            'rows' => ['Page | Avant | Après', 'Accueil | 2,4 s | 0,8 s', ['nested']],
        ]);

        self::assertSame(
            ['title' => 'Temps de réponse', 'rows' => ['Page | Avant | Après', 'Accueil | 2,4 s | 0,8 s']],
            $slide->getContent(),
        );
    }

    /**
     * The three common slots reach a layout that declares none of them, which
     * is the whole point of their being common.
     */
    public function testTheCommonSlotsReachEveryLayout(): void
    {
        $slide = (new Slide())->setLayout(SlideLayoutEnum::Quote);

        $this->manager()->writeContent($slide, [
            'quote' => 'Une phrase.',
            'kicker' => 'Partie 2',
            'bgMediaId' => 7,
            'bgDim' => 55,
        ]);

        self::assertSame(
            ['quote' => 'Une phrase.', 'kicker' => 'Partie 2', 'bgMediaId' => 7, 'bgDim' => 55],
            $slide->getContent(),
        );
    }

    /**
     * The slider is bounded at both ends, so a value outside them was edited by
     * hand. Clamped rather than refused: a veil at 300% is a slide that is only
     * a veil, and losing the sentence somebody typed for it would be worse.
     */
    public function testTheVeilIsClampedRatherThanRefused(): void
    {
        $manager = $this->manager();

        $high = (new Slide())->setLayout(SlideLayoutEnum::Title);
        $manager->writeContent($high, ['bgDim' => 300]);
        self::assertSame(['bgDim' => 90], $high->getContent());

        $low = (new Slide())->setLayout(SlideLayoutEnum::Title);
        $manager->writeContent($low, ['bgDim' => -20]);
        self::assertSame(['bgDim' => 0], $low->getContent());
    }

    /**
     * `mediaFocus` is written into `object-position`.
     *
     * A string that is not a position there does not fail: it makes the
     * declaration invalid and the picture quietly re-centres, which reads as a
     * choice being ignored rather than as an error.
     */
    public function testTheFocalPointMustBeTwoPercentages(): void
    {
        $manager = $this->manager();

        $good = (new Slide())->setLayout(SlideLayoutEnum::Image);
        $manager->writeContent($good, ['mediaFocus' => '50% 18%']);
        self::assertSame(['mediaFocus' => '50% 18%'], $good->getContent());

        foreach (['top left', '50%18%', '50px 18px', 'center', '50% 18%; z-index: 9'] as $wrong) {
            $slide = (new Slide())->setLayout(SlideLayoutEnum::Image);
            $manager->writeContent($slide, ['mediaFocus' => $wrong]);
            self::assertSame([], $slide->getContent(), sprintf('"%s" is not a focal point', $wrong));
        }
    }

    public function testTheFitIsOneOfTheTwoTheFrameKnows(): void
    {
        $manager = $this->manager();

        $cover = (new Slide())->setLayout(SlideLayoutEnum::Image);
        $manager->writeContent($cover, ['mediaFit' => 'cover']);
        self::assertSame(['mediaFit' => 'cover'], $cover->getContent());

        $nonsense = (new Slide())->setLayout(SlideLayoutEnum::Image);
        $manager->writeContent($nonsense, ['mediaFit' => 'scale-down']);
        self::assertSame([], $nonsense->getContent());
    }

    /** The address is derived at render, never stored beside the id. */
    public function testADerivedPictureAddressIsNeverPersisted(): void
    {
        $slide = (new Slide())->setLayout(SlideLayoutEnum::Image);

        $this->manager()->writeContent($slide, [
            'mediaId' => 42,
            'mediaUrl' => 'https://elsewhere.example/forged.png',
            'mediaFocusDefault' => '10% 90%',
            'bgMediaId' => 43,
            'bgMediaUrl' => 'https://elsewhere.example/forged-too.png',
        ]);

        self::assertSame(['mediaId' => 42, 'bgMediaId' => 43], $slide->getContent());
    }

    /**
     * Every layout's slots must be reachable, or a field exists in the editor
     * that the manager throws away on save.
     */
    public function testEveryLayoutDeclaresAtLeastOneSlot(): void
    {
        foreach (SlideLayoutEnum::cases() as $layout) {
            self::assertNotEmpty($layout->slots(), sprintf('layout "%s" declares no slot', $layout->value));
        }
    }

    /**
     * A stub rather than a mock: `writeContent` never touches the entity
     * manager, so there is nothing to expect of it and PHPUnit says so.
     *
     * The normalizer is the real one: it has no collaborators, and stubbing it
     * would only make this test agree with itself about what a style may hold.
     */
    private function manager(): DeckManager
    {
        return new DeckManager($this->createStub(EntityManagerInterface::class), new DeckStyleNormalizer());
    }
}
