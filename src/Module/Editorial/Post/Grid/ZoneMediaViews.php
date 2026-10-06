<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Grid;

use Aurora\Core\Storage\Enum\MimeGroupEnum;
use Aurora\Core\Storage\Enum\MimeTypeEnum;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Service\DocumentCreditPresenter;
use Aurora\Module\Ged\Document\Service\DocumentUrlGenerator;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;

/**
 * The zones built from the médiathèque: a picture, a before/after, a gallery,
 * a map, a travel map, an item list, a film, a sound, a document card, and a
 * zone's own background.
 *
 * Split off {@see GridViewBuilder}, which loads the documents once for the
 * whole grid and hands them here - so none of these fetches anything.
 */
final readonly class ZoneMediaViews
{
    public function __construct(
        private DocumentUrlGenerator $documentUrlGenerator,
        private DocumentCreditPresenter $creditPresenter,
    ) {}

    public function mediaData(?DocumentInterface $media, string $alt, ?string $url = null): ?array
    {
        // The library wins whenever it has an answer: a document carries a
        // focal point, a rendition sized for this slot and an alt of its own,
        // and none of that can be read off an address. The address is what an
        // author has while a page is being drafted, not a second way of doing
        // the same thing.
        if (!$media instanceof DocumentInterface) {
            return null === $url ? null : [
                'url' => $url,
                'alt' => $alt,
                // Nothing to focus on: an address says where a picture is, not
                // what matters inside it. Centre is what `object-cover` does
                // without instruction anyway, and stating it keeps the template
                // free of a second branch.
                'focalPosition' => '50% 50%',
            ];
        }

        // A media zone renders an `<img>`, so what it holds has to be an
        // image. The suite picker only ever offers those, but three paths
        // reach past it - a fixture, an API write, and a document whose file
        // is replaced after the zone was configured - and an `<img>` pointed
        // at an mp4 is a broken image with nothing said anywhere.
        //
        // Asked here rather than refused in `GridNormalizer` for two reasons.
        // The normaliser has no database and runs on every render, not only on
        // the way in - giving it a repository would put a query behind every
        // page view. And the third path above has no write to refuse: a layout
        // that was valid the day it was saved stops being valid the day the
        // file behind it changes. Only the render knows.
        if (!MimeGroupEnum::Image->matches($media->getMimeType())) {
            return null;
        }

        $url = $this->documentUrlGenerator->renditionUrl($media, 'large')
            ?? $this->documentUrlGenerator->publicUrl($media);

        // A document can carry no file at all - the demo library keeps three
        // that way on purpose, so the upload flow has something to be tested
        // against. Without this the zone emitted `<img src="">`, which is a
        // broken image rather than an absent one.
        if (null === $url) {
            return null;
        }

        return [
            'url' => $url,
            // The zone's own alt wins: the same picture can mean different
            // things in two places, and the document's alt describes the file.
            'alt' => '' !== $alt ? $alt : (string) $media->getAlt(),
            'focalPosition' => $this->documentUrlGenerator->focalPositionCss($media),
            // The camera line: « Canon EOS R6 · f/2.8 · 1/500 s · ISO 100 ».
            // Empty for a picture uploaded before its settings were read, or
            // one whose camera wrote nothing.
            'exif' => implode(' · ', array_values(array_intersect_key(
                $media->getExif(),
                array_flip(['camera', 'lens', 'focal', 'aperture', 'shutter', 'iso']),
            ))),
            // **What reserves the space before the image arrives.** Without
            // both, a lazy-loaded `<img>` is zero pixels tall: the page is
            // short, then grows with each image that lands, and the content
            // is seen moving down in jolts. Measured on the photography
            // page, where three images make up the whole page.
            //
            // These are the document's dimensions while the address is that
            // of a generated size, which does not matter: the browser only
            // takes a ratio from them, and a generated size is a resize.
            // Checked in production, where a photo's "large" size measures
            // exactly what the document declares.
            'width' => $media->getWidth(),
            'height' => $media->getHeight(),
            // Null for anything we host ourselves. Present, and displayed by
            // the template, for a stock photo whose licence requires it.
            'credit' => $this->creditPresenter->present($media),
        ];
    }

    /**
     * Before and after, or nothing.
     *
     * Both or neither, decided here rather than by the template: one picture
     * of a pair is not a comparison, and a handle with nothing on its right is
     * a control that lies about what it does. The same reasoning the button
     * zone applies to its label and its address.
     *
     * The words under each side are translated, and the template supplies a
     * default when the author typed none: "before" and "after" are what they
     * say in nine cases out of ten, and asking every time is asking for
     * nothing.
     *
     * @param array<string, mixed>          $zone
     * @param array<string, mixed>          $held
     * @param array<int, DocumentInterface> $documents
     *
     * @return array{before: array<string, mixed>, after: array<string, mixed>, beforeLabel: string, afterLabel: string}|null
     */
    public function compareView(array $zone, array $held, array $documents): ?array
    {
        $ids = is_array($zone['mediaIds'] ?? null) ? $zone['mediaIds'] : [];

        if (2 !== count($ids)) {
            return null;
        }

        $before = $this->mediaData($documents[$ids[0]] ?? null, '');
        $after = $this->mediaData($documents[$ids[1]] ?? null, '');

        if (null === $before || null === $after) {
            return null;
        }

        return [
            'before' => $before,
            'after' => $after,
            // `alt` and `label` rather than two fields of their own: the two
            // spare translated slots a zone already carries, used for the two
            // words this one needs. Empty when the author typed nothing, and
            // the template falls back to a translated default - this class has
            // no translator, and a French word hard-coded here would be a
            // French word on an English page.
            'beforeLabel' => (string) $held['alt'],
            'afterLabel' => (string) $held['label'],
        ];
    }

    /**
     * The pictures of a gallery zone, resolved against the one prefetch.
     *
     * A document named here but since deleted, or replaced by something that
     * is not a picture, drops out rather than leaving a hole: {@see mediaData}
     * already answers that question and this only has to respect the answer.
     *
     * `ratioStyle` is empty when the zone asks for its own proportions, and
     * that emptiness is what the template reads to flow the pictures down
     * columns instead of cropping them into a grid.
     *
     * @param array<string, mixed>          $zone
     * @param array<int, DocumentInterface> $documents
     *
     * @return array{columns: int, ratioStyle: string, items: list<array<string, mixed>>}
     */
    public function galleryView(array $zone, array $documents): array
    {
        $items = [];

        foreach (is_array($zone['mediaIds'] ?? null) ? $zone['mediaIds'] : [] as $id) {
            $picture = $this->mediaData($documents[$id] ?? null, '');

            if (null !== $picture) {
                $items[] = $picture;
            }
        }

        return [
            'columns' => (int) $zone['columns'],
            'ratioStyle' => $this->ratioStyle($zone['ratio']),
            'items' => $items,
        ];
    }

    /**
     * An address, and a way to be taken to it.
     *
     * Nothing here reaches a provider while the page is being read: the
     * address is text the author typed, the picture is one they chose, and the
     * link is only followed if the reader decides to. That is the whole design
     * of this zone - a draggable map would be a third party on every view,
     * chosen once by us for every client.
     *
     * The link goes to Google Maps' universal address, which is what opens the
     * native application on Android and iOS and a page anywhere else. It is a
     * choice rather than a neutrality: OpenStreetMap would not profile anyone,
     * and would not open the application a reader already navigates with. One
     * line to change here if the trade is judged the other way.
     *
     * @param string|null $name  what the place is called, in this language
     * @param string|null $lines the address as typed, one line per line
     *
     * @return array{name: string, lines: list<string>, directionsUrl: string, media: array<string, mixed>|null}|null
     */
    public function mapView(?string $name, ?string $lines, ?DocumentInterface $media): ?array
    {
        $address = [];
        foreach (explode("\n", (string) $lines) as $line) {
            $line = mb_trim($line);

            if ('' !== $line) {
                $address[] = $line;
            }
        }

        // A zone with no address is not a place, whatever else it carries. A
        // name and a photograph alone would draw a card that cannot answer the
        // one question it is there for.
        if ([] === $address) {
            return null;
        }

        return [
            'name' => (string) $name,
            'lines' => $address,
            // Joined by commas rather than by the newlines it was typed with:
            // a query string carrying line breaks is a query string that has
            // to be repaired at the other end.
            'directionsUrl' => 'https://www.google.com/maps/search/?api=1&query='
                .rawurlencode(implode(', ', $address)),
            'media' => $this->mediaData($media, ''),
        ];
    }

    /**
     * A trip's stops, paired by position with the gallery of photos the
     * author picked - the same pairing a compare zone makes between its two
     * slots, extended to as many as there are.
     *
     * A line the parser cannot read (not exactly three parts, or a latitude
     * or longitude that is not a plain number) is dropped rather than
     * guessed: a pin planted at 0°N 0°E from a typo is worse than a pin
     * missing.
     *
     * **Photos pair with stops, not with raw lines.** A blank line is layout
     * and takes no photo; it used to, and one stray blank line in a
     * translation moved every later photo onto the wrong stop. A line that
     * is written but unreadable still counts as a stop, dropped or not, so a
     * typo does not shift the photos after it either.
     *
     * @param array<string, mixed>          $zone
     * @param array<string, mixed>          $held
     * @param array<int, DocumentInterface> $documents
     *
     * @return array{stops: list<array<string, mixed>>}|null
     */
    public function travelMapView(array $zone, array $held, array $documents): ?array
    {
        $stops = [];
        $position = -1;

        foreach (explode("\n", (string) $held['code']) as $line) {
            if ('' === mb_trim($line)) {
                continue;
            }

            ++$position;
            $parts = array_map(trim(...), explode('|', $line));
            if (3 !== count($parts)) {
                continue;
            }

            if (!is_numeric($parts[1])) {
                continue;
            }

            if (!is_numeric($parts[2])) {
                continue;
            }

            if ('' === $parts[0]) {
                continue;
            }

            $lat = (float) $parts[1];
            $lng = (float) $parts[2];
            if ($lat < -90) {
                continue;
            }

            if ($lat > 90) {
                continue;
            }

            if ($lng < -180) {
                continue;
            }

            if ($lng > 180) {
                continue;
            }

            $mediaId = $zone['mediaIds'][$position] ?? null;
            $photo = null !== $mediaId ? $this->mediaData($documents[$mediaId] ?? null, $parts[0]) : null;

            $stops[] = [
                'label' => $parts[0],
                'lat' => $lat,
                'lng' => $lng,
                'photo' => $photo,
            ];
        }

        return [] === $stops ? null : ['stops' => $stops];
    }

    /**
     * An item list, joined back together: the arrangement says how many
     * entries there are and which picture each carries, the translation says
     * what they read.
     *
     * An entry whose words are all empty is dropped. A list is authored by
     * adding rows and filling them in, so the blank one at the end is the one
     * being written - it belongs in the editor, not on the page.
     *
     * @param array<string, mixed>          $zone
     * @param array<string, mixed>          $held      this locale's content for the zone
     * @param array<int, DocumentInterface> $documents
     *
     * @return array{display: string, columns: int, entries: list<array<string, mixed>>}
     */
    public function itemsView(array $zone, array $held, array $documents): array
    {
        $texts = is_array($held['items'] ?? null) ? $held['items'] : [];
        $entries = [];

        foreach (is_array($zone['items'] ?? null) ? $zone['items'] : [] as $item) {
            $id = $item['id'] ?? null;
            if (!is_string($id)) {
                continue;
            }

            $words = is_array($texts[$id] ?? null) ? $texts[$id] : [];
            $media = $this->mediaData($documents[$item['mediaId']] ?? null, '');

            $title = (string) ($words['title'] ?? '');
            $description = (string) ($words['description'] ?? '');
            $caption = (string) ($words['caption'] ?? '');

            if ('' === $title && '' === $description && '' === $caption && null === $media) {
                continue;
            }

            $entries[] = [
                'id' => $id,
                'title' => $title,
                'description' => $description,
                'caption' => $caption,
                'url' => $words['url'] ?? null,
                'media' => $media,
                // Only the offers costume draws it, but it travels with every
                // entry: reading it in the template is one `default`, and
                // deciding here which costumes may carry it would put the
                // costume's business in the wrong file.
                'featured' => (bool) ($item['featured'] ?? false),
                // 1-based, for the display that numbers its steps. Worked out
                // here rather than in the template, which would have to count
                // the entries it skipped.
                'position' => count($entries) + 1,
            ];
        }

        return [
            'display' => (string) $zone['display'],
            'columns' => (int) $zone['columns'],
            'entries' => $entries,
            // Carried into the view rather than read off the zone in Twig,
            // because `_grid_items` is handed `items` and nothing else - and
            // giving it the whole zone to reach one flag would hand it the
            // span, the surface and the anchor as well.
            'exclusiveOpen' => (bool) ($zone['exclusiveOpen'] ?? false),
            // The colour of the entry put forward, from the zone's options.
            // Normalised on the way in; read with a default here so a zone
            // saved before the setting existed keeps the accent it had.
            'featuredTone' => (string) ($zone['options']['featuredTone'] ?? 'accent'),
            // The grouping name the browser folds on. Per zone, so two lists
            // on one page do not close each other's panels; `id` is already
            // unique across the grid, stacks included.
            'id' => (string) $zone['id'],
        ];
    }

    /**
     * The same entries, in the shape the editor keeps them in.
     *
     * Identity and order exactly as stored - the ids are what each entry's
     * words are filed under, so an arrangement that comes back without them
     * comes back as different entries - plus the picture resolved, so the
     * picker shows the logo it already holds rather than a number.
     *
     * Blank entries are kept, unlike the page's view: a row typed into
     * tomorrow is a row today.
     *
     * Every stored field the editor sends back has to come out here too. The
     * recommended flag of an offer did not, so the editor read every card as
     * plain and the next save, from any tab, took the badge off the page.
     *
     * @param array<string, mixed>          $zone
     * @param array<int, DocumentInterface> $documents
     *
     * @return list<array{id: string, mediaId: int|null, media: array<string, mixed>|null, featured: bool}>
     */
    public function itemsForEditor(array $zone, array $documents): array
    {
        $items = [];

        foreach (is_array($zone['items'] ?? null) ? $zone['items'] : [] as $item) {
            $id = $item['id'] ?? null;
            if (!is_string($id)) {
                continue;
            }

            $mediaId = $item['mediaId'] ?? null;

            $items[] = [
                'id' => $id,
                'mediaId' => is_int($mediaId) ? $mediaId : null,
                'media' => $this->mediaData($documents[$mediaId] ?? null, ''),
                'featured' => (bool) ($item['featured'] ?? false),
            ];
        }

        return $items;
    }

    /**
     * A video the library holds, ready for a `<video>`.
     *
     * The mime is checked rather than trusted: the picker offers videos, but a
     * fixture, an API write or a file replaced after the zone was configured
     * all reach past it - and a player pointed at a PDF is a black rectangle
     * with nothing said anywhere. Same reasoning as {@see mediaData}, and the
     * same place to ask it: only the render knows what the file is today.
     *
     * @return array{url: string, mimeType: string, poster: string|null, width: int|null, height: int|null}|null
     */
    public function videoFile(?DocumentInterface $media): ?array
    {
        if (!$media instanceof DocumentInterface) {
            return null;
        }

        $mime = MimeTypeEnum::tryFrom((string) $media->getMimeType());

        if (!$mime?->isVideo()) {
            return null;
        }

        $url = $this->documentUrlGenerator->publicUrl($media);

        if (null === $url) {
            return null;
        }

        return [
            'url' => $url,
            'mimeType' => $mime->value,
            // The still the player shows before anything is downloaded.
            'poster' => $this->documentUrlGenerator->thumbnailPathUrl($media),
            // The film's own pixel size, so the box is the right shape before
            // a single byte is fetched. Without it a `preload="none"` player
            // falls back to the browser's 300x150 default, which is why an
            // unplayed portrait film used to render as a squat black
            // rectangle. Null when the document predates the column.
            'width' => $media->getWidth(),
            'height' => $media->getHeight(),
        ];
    }

    /**
     * A recording the library holds, for a player the browser draws itself.
     *
     * Asked at render for the reason {@see videoFile} is: a zone configured
     * with a recording stays configured with it after the file behind it is
     * replaced by a spreadsheet, and only the render knows what it is today.
     * A player pointed at the wrong thing is a silent control that does
     * nothing, with no message anywhere.
     *
     * No poster and no dimensions, unlike a film: a `<audio>` element has a
     * height of its own that owes nothing to what it plays.
     *
     * @return array{url: string, mimeType: string}|null
     */
    public function audioFile(?DocumentInterface $media): ?array
    {
        if (!$media instanceof DocumentInterface) {
            return null;
        }

        if (!MimeGroupEnum::Audio->matches($media->getMimeType())) {
            return null;
        }

        // Published only, for the reason {@see documentCard} gives, and it
        // bites harder here. Since `/uploads` began withholding anything not
        // published, {@see DocumentUrlGenerator::publicUrl} hands back the
        // suite address for a draft - so a zone naming one would draw a
        // player that answers 403 to every visitor, silently. A picture in
        // that state at least shows a broken image; a dead player shows
        // nothing at all and reads as a site that does not work.
        if (DocumentStatusEnum::Published !== $media->getStatus()) {
            return null;
        }

        $url = $this->documentUrlGenerator->publicUrl($media);

        if (null === $url) {
            return null;
        }

        return [
            'url' => $url,
            'mimeType' => (string) $media->getMimeType(),
        ];
    }

    /**
     * A file offered for download, as the card that describes it.
     *
     * **Published only.** A library holds a client's internal papers beside
     * the ones they hand out, and the status column is what already tells them
     * apart; a draft named in a zone renders as nothing rather than as a link.
     * That is the whole of the check, and it is worth being plain about what it
     * is not: the file itself is served by a public route, so this decides what
     * a page *advertises*, not what the server will hand over to somebody who
     * already has the address.
     *
     * The extension comes off the original name rather than off the mime type:
     * it is what the reader will see in their downloads folder, and `xlsx` says
     * more to them than `application/vnd.openxmlformats-officedocument…` ever
     * will.
     *
     * @param string|null $label what the control says, in the page's language;
     *                           the document's own title when nothing is typed
     *
     * @return array{title: string, url: string, extension: string, size: int|null}|null
     */
    public function documentCard(?DocumentInterface $media, ?string $label): ?array
    {
        if (!$media instanceof DocumentInterface) {
            return null;
        }

        if (DocumentStatusEnum::Published !== $media->getStatus()) {
            return null;
        }

        $url = $this->documentUrlGenerator->publicUrl($media);

        if (null === $url) {
            return null;
        }

        $extension = pathinfo((string) $media->getOriginalName(), PATHINFO_EXTENSION);

        return [
            'title' => null !== $label && '' !== $label ? $label : $media->getTitle(),
            'url' => $url,
            'extension' => mb_strtoupper($extension),
            'size' => $media->getSize(),
        ];
    }

    /**
     * A `custom` surface's colour, gradient or picture, ready for the
     * template - mirrors {@see BannerViewBuilder::fillStyle()} and its own
     * `mediaData`, one call site rather than two renderers.
     *
     * @param array<string, mixed>          $background a normalised zone background
     * @param array<int, DocumentInterface> $documents  every document this render already fetched
     *
     * @return array{fillStyle: ?string, media: ?array<string, mixed>, overlay: int}
     */
    public function zoneBackgroundView(array $background, array $documents): array
    {
        return [
            'fillStyle' => match ($background['type']) {
                GridNormalizer::ZONE_FILL_SOLID => null !== $background['color']
                    ? sprintf('background-color: %s;', $background['color'])
                    : null,
                GridNormalizer::ZONE_FILL_GRADIENT => null !== $background['gradientFrom'] && null !== $background['gradientTo']
                    ? sprintf(
                        'background-image: linear-gradient(%ddeg, %s, %s);',
                        $background['gradientAngle'],
                        $background['gradientFrom'],
                        $background['gradientTo'],
                    )
                    : null,
                default => null,
            },
            'media' => $this->mediaData($documents[$background['mediaId']] ?? null, ''),
            // Reuses the dedicated video zone's own resolver: the mime is
            // checked here rather than trusted from the layout, the same
            // reasoning as there - a file can be replaced after the zone was
            // configured.
            'video' => $this->videoFile($documents[$background['videoId']] ?? null),
            'overlay' => $background['overlay'],
        ];
    }

    /**
     * The crop, as a declaration rather than a class.
     *
     * A Tailwind class would have to be written out somewhere Tailwind reads -
     * `aspect-video` happens to appear in this module's Twig, but
     * `aspect-square` and `aspect-[3/4]` appear nowhere, so choosing them here
     * would emit nothing and the crop would silently not happen. The project
     * already answered this question for spans, which go out as custom
     * properties for the same reason. `ThumbnailFitEnum::objectFitClass()`
     * returns classes from PHP and gets away with it only because those strings
     * exist in unrelated Vue files.
     *
     * Empty for `natural`, so the caller can test it and the style attribute
     * stays clean.
     */
    public function ratioStyle(string $ratio): string
    {
        return match ($ratio) {
            '16x9' => 'aspect-ratio: 16 / 9;',
            '4x3' => 'aspect-ratio: 4 / 3;',
            '1x1' => 'aspect-ratio: 1 / 1;',
            '3x4' => 'aspect-ratio: 3 / 4;',
            // `fill` and `natural` both land here: neither states a ratio. What
            // separates them is a height, which is a class on the element
            // rather than a declaration - see `_grid_zone.html.twig`.
            default => '',
        };
    }
}
