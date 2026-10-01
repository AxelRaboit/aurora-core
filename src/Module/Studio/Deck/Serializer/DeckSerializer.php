<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Serializer;

use Aurora\Core\Content\VideoEmbedResolver;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\Deck\Entity\DeckCategoryInterface;
use Aurora\Module\Studio\Deck\Entity\DeckInterface;
use Aurora\Module\Studio\Deck\Entity\SlideInterface;
use Aurora\Module\Studio\Deck\Service\DeckAppearance;
use Aurora\Module\Studio\Deck\Service\DeckPicture;
use Aurora\Module\Studio\Deck\Service\DeckPictures;
use Aurora\Module\Studio\Deck\Service\DeckVideo;

use function array_diff;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function is_array;
use function is_int;
use function sprintf;

use const DATE_ATOM;

class DeckSerializer
{
    public function __construct(
        private readonly DeckPicture $pictures,
        private readonly DeckAppearance $appearance,
        private readonly DeckPictures $deckPictures,
        private readonly DeckVideo $videos,
        private readonly VideoEmbedResolver $embeds,
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
            'isTemplate' => $deck->isTemplate(),
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
        $ids = $this->deckPictures->idsUsedBy($deck);
        $pictures = $this->pictures->byIds($ids);
        // The same ids, asked of the films: one id is either a picture or a
        // film, and each resolver refuses what is not its own.
        $videos = $this->videos->byIds($ids);

        $slides = [];
        foreach ($deck->getSlides() as $slide) {
            $slides[] = $this->slide($slide, $pictures, $videos);
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
     * @param array<int, array{url: string, alt: string, focus: string}>            $pictures already-resolved pictures, by id
     * @param array<int, array{url: string, poster: string|null, mimeType: string}> $videos   already-resolved films, by id
     *
     * @return array<string, mixed>
     */
    public function slide(SlideInterface $slide, array $pictures = [], array $videos = []): array
    {
        $content = $slide->getContent();

        if ($slide->getLayout()->isFree()) {
            $content = $this->freeContent($content, $pictures, $videos);
        }

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

        // Derived like the single one, and aligned with the stored order: the
        // arrangement is the order somebody picked, so the frame must be able
        // to draw the first one first without looking anything up.
        if (is_array($content['mediaIds'] ?? null)) {
            // Alignee sur `mediaIds` position par position, trous compris.
            // Compactee, elle decalait tout ce qui suit une image supprimee de
            // la mediatheque : le formulaire montrait la vignette suivante sous
            // l'identifiant precedent, et remplacer une case en modifiait une
            // autre.
            $content['mediaPictures'] = array_map(
                function (mixed $id) use ($pictures): ?array {
                    if (!is_int($id)) {
                        return null;
                    }

                    $picture = $pictures[$id] ?? $this->pictures->byId($id);

                    if (null === ($picture['url'] ?? null)) {
                        return null;
                    }

                    return [
                        'url' => $picture['url'],
                        'alt' => $picture['alt'],
                        'focus' => $picture['focus'],
                    ];
                },
                $content['mediaIds'],
            );
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

    /**
     * A free slide's content, with what each element needs to be drawn.
     *
     * Derived and never stored, like `mediaUrl` on a laid-out slide: the
     * normalizer whitelists an element's keys and none of these is one, so a
     * payload sending them back is dropped on the way in.
     *
     * @param array<string, mixed>                                                  $content
     * @param array<int, array{url: string, alt: string, focus: string}>            $pictures
     * @param array<int, array{url: string, poster: string|null, mimeType: string}> $videos
     *
     * @return array<string, mixed>
     */
    private function freeContent(array $content, array $pictures, array $videos): array
    {
        // A slide serialised on its own - after a write - arrives without the
        // deck's films resolved. The missing ones are fetched in one query
        // rather than one per element.
        $wanted = [];
        foreach (is_array($content['elements'] ?? null) ? $content['elements'] : [] as $element) {
            if (is_array($element) && 'video' === ($element['type'] ?? null) && is_int($element['mediaId'] ?? null)) {
                $wanted[] = $element['mediaId'];
            }
        }

        $backgroundVideo = $content['bgVideoId'] ?? null;

        if (is_int($backgroundVideo)) {
            $wanted[] = $backgroundVideo;
        }

        $missing = array_values(array_diff(array_unique($wanted), array_keys($videos)));

        if ([] !== $missing) {
            $videos += $this->videos->byIds($missing);
        }

        if (is_int($backgroundVideo)) {
            $film = $videos[$backgroundVideo] ?? null;

            $content['bgVideoUrl'] = $film['url'] ?? null;
            $content['bgVideoPoster'] = $film['poster'] ?? null;
        }

        if (!is_array($content['elements'] ?? null)) {
            return $content;
        }

        $content['elements'] = array_map(
            function (mixed $element) use ($pictures, $videos): mixed {
                if (!is_array($element)) {
                    return $element;
                }

                $mediaId = $element['mediaId'] ?? null;

                if ('image' === ($element['type'] ?? null) && is_int($mediaId)) {
                    $picture = $pictures[$mediaId] ?? $this->pictures->byId($mediaId);

                    $element['mediaUrl'] = $picture['url'] ?? null;
                    $element['mediaAlt'] = $picture['alt'] ?? '';
                    $element['mediaFocusDefault'] = $picture['focus'] ?? '50% 50%';
                }

                if ('video' === ($element['type'] ?? null) && is_int($mediaId)) {
                    $film = $videos[$mediaId] ?? null;

                    $element['videoUrl'] = $film['url'] ?? null;
                    $element['poster'] = $film['poster'] ?? null;
                    $element['mimeType'] = $film['mimeType'] ?? null;
                }

                if ('embed' === ($element['type'] ?? null)) {
                    $resolved = $this->embeds->resolve($element['url'] ?? null);

                    $element['embedUrl'] = $resolved['embedUrl'] ?? null;
                    $element['provider'] = $resolved['provider'] ?? null;
                    // YouTube publishes a still for every film at a fixed
                    // address; the others need an API call, and get the
                    // player's own placeholder instead.
                    $element['thumbnail'] = VideoEmbedResolver::YOUTUBE === ($resolved['provider'] ?? null)
                        ? sprintf('https://i.ytimg.com/vi/%s/hqdefault.jpg', $resolved['id'])
                        : null;
                }

                return $element;
            },
            $content['elements'],
        );

        return $content;
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
