<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Studio\Deck;

use Aurora\Module\Studio\Deck\Entity\Slide;
use Aurora\Module\Studio\Deck\Enum\SlideLayoutEnum;
use Aurora\Module\Studio\Deck\Manager\DeckManager;
use Aurora\Module\Studio\Deck\Service\DeckStyleNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

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

    /** A picture is an id, and a string there would fail to resolve at render. */
    public function testItRefusesAMediaIdThatIsNotAnInteger(): void
    {
        $slide = (new Slide())->setLayout(SlideLayoutEnum::Image);

        $this->manager()->writeContent($slide, ['mediaId' => '42', 'caption' => 'La façade']);

        self::assertSame(['caption' => 'La façade'], $slide->getContent());
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
