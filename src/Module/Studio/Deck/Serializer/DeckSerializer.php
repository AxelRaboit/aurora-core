<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Serializer;

use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\Deck\Entity\DeckCategoryInterface;
use Aurora\Module\Studio\Deck\Entity\DeckInterface;
use Aurora\Module\Studio\Deck\Entity\SlideInterface;
use Aurora\Module\Studio\Deck\Service\DeckAppearance;
use Aurora\Module\Studio\Deck\Service\DeckPicture;

use function count;
use function is_int;

use const DATE_ATOM;

class DeckSerializer
{
    public function __construct(
        private readonly DeckPicture $pictures,
        private readonly DeckAppearance $appearance,
    ) {}

    /**
     * A deck as the list shows it: no slides, a count instead.
     *
     * The list draws thirty rows and none of them shows a slide's contents.
     * Sending them would be the whole deck thirty times over for a number the
     * caller already has.
     *
     * @return array<string, mixed>
     */
    public function summary(DeckInterface $deck, int $slideCount = 0): array
    {
        return [
            'id' => $deck->getId(),
            'title' => $deck->getTitle(),
            'description' => $deck->getDescription(),
            'category' => $this->category($deck->getCategory()),
            'customer' => $deck->getCustomer() instanceof CustomerInterface ? [
                'id' => $deck->getCustomer()->getId(),
                'legalName' => $deck->getCustomer()->getLegalName(),
            ] : null,
            'theme' => $deck->getTheme()->value,
            'slideCount' => $slideCount,
            'updatedAt' => $deck->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    /**
     * A deck as its own page shows it: slides included, in order.
     *
     * `appearance` is what the frame draws with, theme and overrides already
     * merged; `style` is the raw overrides, which is what the appearance panel
     * edits. Both, because the two answer different questions: "what colour is
     * this slide" and "did somebody choose that colour, or is it the theme's".
     *
     * @return array<string, mixed>
     */
    public function full(DeckInterface $deck): array
    {
        // The pictures resolved in one query rather than one per slide: a deck
        // of thirty slides is thirty round trips otherwise, for a handful of
        // ids that are known before the loop starts.
        $ids = [];
        foreach ($deck->getSlides() as $slide) {
            foreach (['mediaId', 'bgMediaId'] as $slot) {
                $id = $slide->getContent()[$slot] ?? null;

                if (is_int($id)) {
                    $ids[] = $id;
                }
            }
        }

        $pictures = $this->pictures->byIds($ids);

        $slides = [];
        foreach ($deck->getSlides() as $slide) {
            $slides[] = $this->slide($slide, $pictures);
        }

        return [
            ...$this->summary($deck, count($slides)),
            'style' => $deck->getStyle(),
            'appearance' => $this->appearance->resolve($deck),
            'slides' => $slides,
        ];
    }

    /**
     * A deck's look alone, theme and overrides merged.
     *
     * Exposed here so a caller that only needs the appearance does not go
     * around the serializer to get it: one door in front of the resolution
     * means one place to change the day it carries something more.
     *
     * @return array<string, mixed>
     */
    public function appearanceOf(DeckInterface $deck): array
    {
        return $this->appearance->resolve($deck);
    }

    /**
     * @param array<int, array{url: string, alt: string, focus: string}> $pictures already-resolved pictures, by id
     *
     * @return array<string, mixed>
     */
    public function slide(SlideInterface $slide, array $pictures = []): array
    {
        $content = $slide->getContent();

        // `mediaUrl`, `mediaAlt` and `bgMediaUrl` are derived, never stored: the
        // manager whitelists the content against the layout's slots and none of
        // them is one, so a payload carrying them back is dropped rather than
        // persisted. The address of a picture changes when its file does, and a
        // copy of it in the slide would be a second truth to keep.
        $mediaId = $content['mediaId'] ?? null;

        if (is_int($mediaId)) {
            $picture = $pictures[$mediaId] ?? $this->pictures->byId($mediaId);

            $content['mediaUrl'] = $picture['url'] ?? null;
            $content['mediaAlt'] = $picture['alt'] ?? '';
            $content['mediaFocusDefault'] = $picture['focus'] ?? '50% 50%';
        }

        $backgroundId = $content['bgMediaId'] ?? null;

        if (is_int($backgroundId)) {
            $background = $pictures[$backgroundId] ?? $this->pictures->byId($backgroundId);

            $content['bgMediaUrl'] = $background['url'] ?? null;
        }

        return [
            'id' => $slide->getId(),
            'layout' => $slide->getLayout()->value,
            'content' => $content,
            'speakerNotes' => $slide->getSpeakerNotes(),
            'position' => $slide->getPosition(),
        ];
    }

    /** @return array<string, mixed>|null */
    public function category(?DeckCategoryInterface $category): ?array
    {
        if (!$category instanceof DeckCategoryInterface) {
            return null;
        }

        return [
            'id' => $category->getId(),
            'name' => $category->getName(),
            'color' => $category->getColor(),
            'position' => $category->getPosition(),
        ];
    }
}
