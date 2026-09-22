<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Manager;

use Aurora\Module\Studio\Deck\Entity\Deck;
use Aurora\Module\Studio\Deck\Entity\DeckInterface;
use Aurora\Module\Studio\Deck\Entity\Slide;
use Aurora\Module\Studio\Deck\Entity\SlideInterface;
use Aurora\Module\Studio\Deck\Enum\DeckThemeEnum;
use Aurora\Module\Studio\Deck\Enum\SlideLayoutEnum;
use Aurora\Module\Studio\Deck\Service\DeckStyleNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

use function array_key_exists;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function max;
use function min;
use function preg_match;

/**
 * Everything that writes a deck goes through here.
 *
 * One place that knows how a deck and its slides are wired together, for the
 * same reason `ContractTemplateDuplicator` insists on it: a second
 * implementation drifts the first time somebody adds a field, and it drifts
 * silently - the copy simply missing something nobody looks for.
 */
class DeckManager
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly DeckStyleNormalizer $styleNormalizer,
    ) {}

    public function create(string $title): DeckInterface
    {
        $deck = new Deck();
        $deck->setTitle($title);

        $this->entityManager->persist($deck);

        return $deck;
    }

    /**
     * Write a deck's appearance: the theme it starts from, and what it changes.
     *
     * The two travel together because they are one panel and one answer: a
     * theme chosen without its overrides being re-read would keep an accent
     * from the previous theme, and a reader who picks `Paper` after `Ink` would
     * get the paper ground under the ink deck's amber.
     *
     * @param array<string, mixed> $style
     */
    public function writeAppearance(DeckInterface $deck, DeckThemeEnum $theme, array $style): DeckInterface
    {
        $deck->setTheme($theme);
        $deck->setStyle($this->styleNormalizer->normalize($style));

        return $deck;
    }

    /**
     * Add a slide at the end of the deck.
     *
     * The position is computed from what is already there rather than from a
     * counter on the deck: the counter would be a second statement of the same
     * fact, and the day a slide is deleted the two disagree.
     */
    public function addSlide(DeckInterface $deck, SlideLayoutEnum $layout): SlideInterface
    {
        $slide = new Slide();
        $slide->setLayout($layout);
        $slide->setPosition($this->nextPosition($deck));

        $deck->addSlide($slide);
        $this->entityManager->persist($slide);

        return $slide;
    }

    /**
     * Write a slide's content, keeping only the slots its layout declares.
     *
     * The whitelist is the layout's own `slots()`, so a new field is one edit
     * in one place. Anything else that arrives is dropped rather than refused:
     * a stale form posting a slot the layout lost is not an error worth
     * showing a reader, it is a field that no longer exists.
     *
     * @param array<string, mixed> $content
     */
    public function writeContent(SlideInterface $slide, array $content): SlideInterface
    {
        $clean = [];

        foreach ($slide->getLayout()->allSlots() as $slot) {
            if (!array_key_exists($slot, $content)) {
                continue;
            }

            $value = $content[$slot];

            // The two picture slots are not text: they point at a document in
            // the library, and a string there would silently fail to resolve at
            // render. A zero or a negative is a picker that was cleared and
            // posted what an empty field holds.
            if ('mediaId' === $slot || 'bgMediaId' === $slot) {
                if (is_int($value) && $value > 0) {
                    $clean[$slot] = $value;
                }

                continue;
            }

            // `mediaFocus` lands in `object-position`, a CSS property, so a
            // string that is not a position there does not fail: it makes the
            // declaration invalid and the picture quietly re-centres, which
            // reads as a choice being ignored. Two percentages or nothing.
            if ('mediaFocus' === $slot) {
                if (is_string($value) && 1 === preg_match('/^\d{1,3}% \d{1,3}%$/', $value)) {
                    $clean[$slot] = $value;
                }

                continue;
            }

            if ('mediaFit' === $slot) {
                if (in_array($value, ['contain', 'cover'], true)) {
                    $clean[$slot] = $value;
                }

                continue;
            }

            // The shape lands on a class the frame matches on, so an unknown
            // one draws nothing rather than drawing wrong. Declared beside
            // `mediaFit` and not as an enum for the same reason it is: four
            // values a select offers, with no behaviour of their own.
            if ('mediaShape' === $slot) {
                if (in_array($value, ['soft', 'round', 'arch', 'circle'], true)) {
                    $clean[$slot] = $value;
                }

                continue;
            }

            // Where the content sits in the frame, how it is aligned and how
            // wide it is allowed to run. Three short declared lists rather than
            // three enums, for the same reason `mediaFit` is one: values a
            // select offers, with no behaviour of their own.
            if ('anchor' === $slot) {
                if (in_array($value, ['top', 'center', 'bottom'], true)) {
                    $clean[$slot] = $value;
                }

                continue;
            }

            if ('align' === $slot) {
                if (in_array($value, ['left', 'center', 'right'], true)) {
                    $clean[$slot] = $value;
                }

                continue;
            }

            if ('measure' === $slot) {
                if (in_array($value, ['full', 'two_thirds', 'half'], true)) {
                    $clean[$slot] = $value;
                }

                continue;
            }

            // Only the true is stored, on the same reasoning as `slideNumbers`
            // in the style: the absence already says "drawn the usual way round",
            // and a stored false would be a second way to spell it.
            if ('inverted' === $slot) {
                if (true === $value) {
                    $clean[$slot] = true;
                }

                continue;
            }

            // Clamped rather than refused: the control is a slider bounded at
            // both ends, so an out-of-range value is a payload edited by hand,
            // and a veil at 300% is a slide that is only a veil.
            if ('bgDim' === $slot) {
                if (is_int($value)) {
                    $clean[$slot] = max(0, min(90, $value));
                }

                continue;
            }

            if (in_array($slot, SlideLayoutEnum::listSlots(), true)) {
                if (is_array($value)) {
                    $clean[$slot] = array_values(array_filter($value, is_string(...)));
                }

                continue;
            }

            if (is_string($value)) {
                $clean[$slot] = $value;
            }
        }

        $slide->setContent($clean);

        return $slide;
    }

    /**
     * Copy one slide, placed right after the one it copies.
     *
     * Through the same `addSlide` and `writeContent` the editor uses, so a slot
     * added to a layout is carried over without anybody remembering to come
     * back here. The positions after the copy are pushed along rather than
     * recomputed from scratch: the rest of the deck has not moved, and
     * rewriting every row would be a hundred updates to insert one.
     */
    public function duplicateSlide(SlideInterface $source): SlideInterface
    {
        $deck = $source->getDeck();

        // A slide always has a deck: the column is not nullable and the only
        // way to hold one is through the deck it belongs to. Said out loud
        // because the getter is nullable for the moment between `new` and the
        // `addSlide` that attaches it.
        if (!$deck instanceof DeckInterface) {
            throw new LogicException('a slide cannot be duplicated before it belongs to a deck');
        }

        $copy = $this->addSlide($deck, $source->getLayout());

        $this->writeContent($copy, $source->getContent());
        $copy->setSpeakerNotes($source->getSpeakerNotes());

        $at = $source->getPosition() + 1;

        foreach ($deck->getSlides() as $slide) {
            if ($slide !== $copy && $slide->getPosition() >= $at) {
                $slide->setPosition($slide->getPosition() + 1);
            }
        }

        $copy->setPosition($at);

        return $copy;
    }

    /**
     * Put the slides in the order given, by id.
     *
     * Ids the deck does not hold are ignored rather than refused: a reorder
     * arriving after somebody else deleted a slide should still place the
     * others, not fail whole.
     *
     * @param list<int> $orderedIds
     */
    public function reorderSlides(DeckInterface $deck, array $orderedIds): void
    {
        $byId = [];
        foreach ($deck->getSlides() as $slide) {
            $byId[$slide->getId()] = $slide;
        }

        $position = 0;
        foreach ($orderedIds as $id) {
            if (!isset($byId[$id])) {
                continue;
            }

            $byId[$id]->setPosition($position);
            ++$position;
            unset($byId[$id]);
        }

        // Whatever the payload did not mention keeps its relative order, after
        // the rest: a slide must never lose its place because a client sent a
        // partial list.
        foreach ($byId as $slide) {
            $slide->setPosition($position);
            ++$position;
        }
    }

    private function nextPosition(DeckInterface $deck): int
    {
        $highest = -1;

        foreach ($deck->getSlides() as $slide) {
            $highest = max($highest, $slide->getPosition());
        }

        return $highest + 1;
    }
}
