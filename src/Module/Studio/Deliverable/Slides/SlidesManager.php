<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Slides;

use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Slides\Entity\Slide;
use Aurora\Module\Studio\Deliverable\Slides\Entity\SlideInterface;
use Aurora\Module\Studio\Deliverable\Slides\Enum\DeckThemeEnum;
use Aurora\Module\Studio\Deliverable\Slides\Enum\SlideLayoutEnum;
use Aurora\Module\Studio\Deliverable\Slides\Service\DeckStyleNormalizer;
use Aurora\Module\Studio\Deliverable\Slides\Service\FreeSlideNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

use function array_filter;
use function array_key_exists;
use function array_slice;
use function array_values;
use function ctype_digit;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function max;
use function min;
use function preg_match;

/**
 * Everything that writes slides goes through here.
 *
 * One place that knows how slides are added, filled, copied and ordered: a
 * second implementation drifts the first time somebody adds a field, and it
 * drifts silently. Slides belong to deliverables in the slides format, which
 * is what Studio's presentations became.
 *
 * Nothing here flushes: the caller decides when its write is whole.
 */
class SlidesManager
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly DeckStyleNormalizer $styleNormalizer,
        protected readonly FreeSlideNormalizer $freeNormalizer,
    ) {}

    /**
     * Write the slides' appearance: the theme they start from, and what they
     * change of it.
     *
     * The two travel together because they are one panel and one answer: a
     * theme chosen without its overrides being re-read would keep an accent
     * from the previous theme, and a reader who picks `Paper` after `Ink` would
     * get the paper ground under the ink deck's amber.
     *
     * @param array<string, mixed> $style
     */
    public function writeAppearance(DeliverableInterface $owner, DeckThemeEnum $theme, array $style): DeliverableInterface
    {
        $owner->setSlideTheme($theme);
        $owner->setSlideStyle($this->styleNormalizer->normalize($style));

        return $owner;
    }

    /**
     * Add a slide at the end.
     *
     * The position is computed from what is already there rather than from a
     * counter on the owner: the counter would be a second statement of the
     * same fact, and the day a slide is deleted the two disagree.
     */
    public function addSlide(DeliverableInterface $owner, SlideLayoutEnum $layout): SlideInterface
    {
        $slide = $this->createSlide();
        $slide->setLayout($layout);
        $slide->setPosition($this->nextPosition($owner));

        $owner->addSlide($slide);
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

            // A free slide's own three: the elements, the paint under them and
            // the film behind them. Each has a shape of its own, so each is
            // cleaned by the class that knows it rather than here.
            if ('elements' === $slot) {
                $clean[$slot] = $this->freeNormalizer->elements($value);

                continue;
            }

            if ('fill' === $slot) {
                $paint = $this->freeNormalizer->paint($value);

                if (null !== $paint) {
                    $clean[$slot] = $paint;
                }

                continue;
            }

            if ('bgVideoId' === $slot) {
                if (is_int($value) && $value > 0) {
                    $clean[$slot] = $value;
                }

                continue;
            }

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

            // What is done to the picture behind the words, and how the veil
            // that keeps them readable is laid over it.
            if ('bgTreatment' === $slot) {
                if (in_array($value, ['none', 'blur', 'mono', 'duotone', 'grain'], true)) {
                    $clean[$slot] = $value;
                }

                continue;
            }

            if ('bgVeil' === $slot) {
                if (in_array($value, ['flat', 'bottom', 'top'], true)) {
                    $clean[$slot] = $value;
                }

                continue;
            }

            if ('mediaFrame' === $slot) {
                if (in_array($value, ['none', 'line', 'shadow'], true)) {
                    $clean[$slot] = $value;
                }

                continue;
            }

            // Which line of an agenda is the one being opened. One-based, so
            // a zero is the value an empty number field posts rather than a
            // line, and anything past the list simply lights nothing.
            // Accepte le nombre ecrit comme une chaine, parce que c'est la
            // seule forme sous laquelle il arrive : le formulaire rend un champ
            // texte pour ce slot, et un `is_int` strict jetait donc toujours la
            // valeur, sans que rien ne le dise.
            if ('current' === $slot) {
                $rank = is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : 0);

                if ($rank > 0) {
                    $clean[$slot] = $rank;
                }

                continue;
            }

            // Several pictures on one slide. Positive integers, in the order
            // they were picked, because position is the arrangement: a
            // mosaic's first picture is the large one. Capped at eight, which
            // is more marks than a grid can show at a legible size anyway.
            if ('mediaIds' === $slot) {
                if (is_array($value)) {
                    $ids = array_values(array_filter(
                        $value,
                        static fn (mixed $one): bool => is_int($one) && $one > 0,
                    ));

                    $clean[$slot] = array_slice($ids, 0, 8);
                }

                continue;
            }

            if ('titleScale' === $slot) {
                if (in_array($value, ['quiet', 'normal', 'loud'], true)) {
                    $clean[$slot] = $value;
                }

                continue;
            }

            // A slide may cut where the deck fades. The rhythm before a
            // section slide is something only that slide knows.
            if ('transition' === $slot) {
                if (in_array($value, ['none', 'fade', 'slide'], true)) {
                    $clean[$slot] = $value;
                }

                continue;
            }

            // A solid shape of accent, and where it sits against the frame.
            if ('band' === $slot) {
                if (in_array($value, ['none', 'left', 'bottom', 'edge'], true)) {
                    $clean[$slot] = $value;
                }

                continue;
            }

            // Three switches, and only their true is kept.
            if (in_array($slot, ['vignette', 'captionOver', 'mediaBleed', 'drift', 'reveal'], true)) {
                if (true === $value) {
                    $clean[$slot] = true;
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

            // Which of the deck's own three colours the slide stands on.
            //
            // One slot rather than a boolean and a colour: "inverted" and
            // "ground: ink" were the same slide drawn twice, and the accent
            // ground had nowhere to live. Three values taken from the palette
            // and never a free colour, so a slide cannot step outside the
            // deck's.
            if ('ground' === $slot) {
                if (in_array($value, ['normal', 'inverted', 'accent'], true)) {
                    $clean[$slot] = $value;
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
     * recomputed from scratch: the rest has not moved, and rewriting every row
     * would be a hundred updates to insert one.
     */
    public function duplicateSlide(SlideInterface $source): SlideInterface
    {
        $owner = $source->getDeliverable();

        // A slide always has its deliverable once it is saved: the only way to
        // hold one is through the deliverable it belongs to. Said out loud
        // because the getter is nullable for the moment between `new` and the
        // `addSlide` that attaches it.
        if (!$owner instanceof DeliverableInterface) {
            throw new LogicException('a slide cannot be duplicated before it belongs to a deliverable');
        }

        $copy = $this->addSlide($owner, $source->getLayout());

        $this->writeContent($copy, $source->getContent());
        $copy->setSpeakerNotes($source->getSpeakerNotes());

        $at = $source->getPosition() + 1;

        foreach ($owner->getSlides() as $slide) {
            if ($slide !== $copy && $slide->getPosition() >= $at) {
                $slide->setPosition($slide->getPosition() + 1);
            }
        }

        $copy->setPosition($at);

        return $copy;
    }

    /** Take a slide out: the row goes with it. */
    public function removeSlide(DeliverableInterface $owner, SlideInterface $slide): void
    {
        $owner->removeSlide($slide);
        $this->entityManager->remove($slide);
    }

    /**
     * The look and the slides of one owner, into another.
     *
     * What a duplicate, a deck opened from a model and a deliverable started
     * from a template all share: the same theme, the same overrides, the same
     * slides in the same order, notes included. Each slide is written through
     * `writeContent`, so a copy is whitelisted like anything typed.
     */
    public function copySlides(DeliverableInterface $target, DeliverableInterface $source): void
    {
        $this->writeAppearance($target, $source->getSlideTheme(), $source->getSlideStyle());

        foreach ($source->getSlides() as $slide) {
            $new = $this->addSlide($target, $slide->getLayout());
            $this->writeContent($new, $slide->getContent());
            $new->setSpeakerNotes($slide->getSpeakerNotes());
        }
    }

    /**
     * Put the slides in the order given, by id.
     *
     * Ids the owner does not hold are ignored rather than refused: a reorder
     * arriving after somebody else deleted a slide should still place the
     * others, not fail whole.
     *
     * @param list<int> $orderedIds
     */
    public function reorderSlides(DeliverableInterface $owner, array $orderedIds): void
    {
        $byId = [];
        foreach ($owner->getSlides() as $slide) {
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

    /**
     * The slide, only if it is this owner's.
     *
     * A slide id from elsewhere must not be writable through a deck or a
     * deliverable the reader happens to be allowed to open. Reading it off the
     * owner rather than from the repository is what makes that structural
     * rather than a check somebody has to remember.
     */
    public function slideOf(DeliverableInterface $owner, int $slideId): ?SlideInterface
    {
        foreach ($owner->getSlides() as $slide) {
            if ($slide->getId() === $slideId) {
                return $slide;
            }
        }

        return null;
    }

    /**
     * The slide this manager writes. A project that extends the slide entity
     * overrides this and receives its own class everywhere a slide is made.
     */
    protected function createSlide(): SlideInterface
    {
        return new Slide();
    }

    private function nextPosition(DeliverableInterface $owner): int
    {
        $highest = -1;

        foreach ($owner->getSlides() as $slide) {
            $highest = max($highest, $slide->getPosition());
        }

        return $highest + 1;
    }
}
