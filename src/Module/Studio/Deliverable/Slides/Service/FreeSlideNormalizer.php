<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Slides\Service;

use Aurora\Core\Content\VideoEmbedResolver;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_slice;
use function array_values;
use function bin2hex;
use function count;
use function fmod;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function max;
use function mb_strtolower;
use function mb_substr;
use function mb_trim;
use function min;
use function preg_match;
use function random_bytes;
use function round;
use function str_starts_with;

/**
 * What a free slide may hold: its elements, and the paint under them.
 *
 * **The same contract as a layout's slots, one level down.** A free slide's
 * content is JSON a page posted, and the frame draws it on a public share link;
 * every key here is declared, every value is bounded, and anything else is
 * dropped rather than refused, for the reason `SlidesManager::writeContent()`
 * gives: a stale editor posting a property that no longer exists is not an
 * error worth showing anybody.
 *
 * **Positions are fractions of the slide**, in per cent of its width and
 * height, and every length that is not a position - a font size, a stroke, a
 * corner, a shadow - is in thousandths of the slide's width. That is what lets
 * the same element be right on a 160-pixel thumbnail, in the editor and on the
 * wall: the frame turns both into `cqw` and `%`, and nothing is ever in pixels.
 *
 * **The order of the list is the stacking order.** The first element is the
 * one at the back; "bring forward" moves it along the list. A `z` property
 * beside the order would be a second statement of the same fact, and the two
 * would disagree the first time an element was duplicated.
 *
 * The lists below are read by the editor too, through the view builder, so a
 * shape added here is offered there without a second list to keep in step.
 */
final readonly class FreeSlideNormalizer
{
    /** How many elements a slide may hold. Past this it is not a slide. */
    public const int MAX_ELEMENTS = 200;

    /** @var list<string> */
    public const array TYPES = ['text', 'image', 'video', 'embed', 'shape', 'icon', 'chart', 'table'];

    /** @var list<string> */
    public const array SHAPES = [
        'rect', 'ellipse', 'triangle', 'right_triangle', 'diamond', 'pentagon', 'hexagon', 'octagon',
        'star', 'star4', 'heart', 'cross', 'parallelogram', 'trapezoid', 'arrow_right', 'arrow_left',
        'chevron', 'speech', 'ring', 'line',
    ];

    /** What a picture or a film is cut to, beyond its rounded corners. */
    public const array MASKS = ['none', 'circle', 'arch', 'triangle', 'diamond', 'hexagon', 'star', 'blob'];

    /** How an element comes in when its slide does, or when its turn comes. */
    public const array ENTERS = ['none', 'fade', 'rise', 'fall', 'left', 'right', 'zoom', 'pop', 'blur'];

    /** @var list<string> */
    public const array ALIGNS = ['left', 'center', 'right', 'justify'];

    /** @var list<string> */
    public const array VALIGNS = ['top', 'middle', 'bottom'];

    /** @var list<string> */
    public const array CASES = ['none', 'upper', 'lower', 'title'];

    /** @var list<string> */
    public const array STROKE_STYLES = ['solid', 'dashed', 'dotted'];

    /** What a line's ends look like. */
    public const array HEADS = ['none', 'end', 'both'];

    /** @var list<string> */
    public const array CHART_TYPES = ['bar', 'line', 'doughnut'];

    /**
     * The adjustments a picture accepts, with the bounds of each and the value
     * that means "untouched", which is never stored.
     *
     * @var array<string, array{0: int, 1: int, 2: int}>
     */
    public const array FILTERS = [
        'brightness' => [0, 200, 100],
        'contrast' => [0, 200, 100],
        'saturate' => [0, 300, 100],
        'grayscale' => [0, 100, 0],
        'sepia' => [0, 100, 0],
        'hue' => [-180, 180, 0],
        'blur' => [0, 40, 0],
    ];

    /**
     * The deck's own three colours, named rather than copied.
     *
     * An element painted `accent` follows the deck the day its palette is
     * changed, which is what a brand colour is for; one painted with the hex
     * the accent had that day would not.
     */
    public const array THEME_COLOURS = ['ink', 'accent', 'background'];

    private const int MAX_STOPS = 6;

    private const int MAX_LIST_LINES = 40;

    private const int MAX_HTML = 20000;

    public function __construct(
        private FreeTextSanitizer $freeTextSanitizer,
        private VideoEmbedResolver $videoEmbedResolver,
    ) {}

    /**
     * The elements, cleaned, in the order they were sent.
     *
     * @return list<array<string, mixed>>
     */
    public function elements(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $clean = [];
        $seen = [];

        foreach (array_slice(array_values($value), 0, self::MAX_ELEMENTS) as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $element = $this->element($raw);

            if (null === $element) {
                continue;
            }

            // Two elements with one id would be selected, moved and deleted
            // together in the editor. A pasted copy keeps its original's id
            // only if nothing else holds it.
            if (isset($seen[$element['id']])) {
                $element['id'] = $this->newId();
            }

            $seen[$element['id']] = true;
            $clean[] = $element;
        }

        return $clean;
    }

    /**
     * A paint: one colour, or a gradient of two to six.
     *
     * @return array<string, mixed>|null
     */
    public function paint(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $type = $value['type'] ?? null;

        if ('solid' === $type) {
            $colour = $this->colour($value['color'] ?? null);

            return null === $colour ? null : ['type' => 'solid', 'color' => $colour];
        }

        if ('linear' !== $type && 'radial' !== $type) {
            return null;
        }

        $stops = [];

        foreach (is_array($value['stops'] ?? null) ? array_values($value['stops']) : [] as $stop) {
            if (!is_array($stop)) {
                continue;
            }

            $colour = $this->colour($stop['color'] ?? null);

            if (null === $colour) {
                continue;
            }

            $stops[] = ['color' => $colour, 'at' => $this->number($stop['at'] ?? 0, 0, 100, 0)];

            if (self::MAX_STOPS === count($stops)) {
                break;
            }
        }

        // A gradient of one colour is a flat colour that took the long way.
        if (count($stops) < 2) {
            return 1 === count($stops) ? ['type' => 'solid', 'color' => $stops[0]['color']] : null;
        }

        $paint = ['type' => $type, 'stops' => $stops];

        if ('linear' === $type) {
            $paint['angle'] = $this->number($value['angle'] ?? 180, 0, 360, 180);
        }

        return $paint;
    }

    /**
     * A colour a browser will draw: hexadecimal with or without its alpha, or
     * one of the deck's own three by name.
     */
    public function colour(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $candidate = mb_strtolower(mb_trim($value));

        if (in_array($candidate, self::THEME_COLOURS, true)) {
            return $candidate;
        }

        return 1 === preg_match('/^#(?:[0-9a-f]{6}|[0-9a-f]{8})$/', $candidate) ? $candidate : null;
    }

    /**
     * Everything the editor needs to offer what this class accepts.
     *
     * @return array<string, mixed>
     */
    public static function options(): array
    {
        return [
            'types' => self::TYPES,
            'shapes' => self::SHAPES,
            'masks' => self::MASKS,
            'enters' => self::ENTERS,
            'aligns' => self::ALIGNS,
            'valigns' => self::VALIGNS,
            'cases' => self::CASES,
            'strokeStyles' => self::STROKE_STYLES,
            'heads' => self::HEADS,
            'chartTypes' => self::CHART_TYPES,
            'filters' => self::FILTERS,
            'maxElements' => self::MAX_ELEMENTS,
        ];
    }

    /**
     * @param array<mixed> $raw
     *
     * @return array<string, mixed>|null
     */
    private function element(array $raw): ?array
    {
        $type = $raw['type'] ?? null;

        if (!is_string($type) || !in_array($type, self::TYPES, true)) {
            return null;
        }

        $id = $raw['id'] ?? null;

        $element = [
            'id' => is_string($id) && 1 === preg_match('/^[A-Za-z0-9_-]{1,32}$/', $id) ? $id : $this->newId(),
            'type' => $type,
            'x' => $this->number($raw['x'] ?? 10, -100, 200, 10),
            'y' => $this->number($raw['y'] ?? 10, -100, 200, 10),
            'w' => $this->number($raw['w'] ?? 30, 0.2, 400, 30),
            'h' => $this->number($raw['h'] ?? 20, 0.2, 400, 20),
        ];

        $rotate = $this->angle($raw['rotate'] ?? 0);
        if (0.0 !== $rotate) {
            $element['rotate'] = $rotate;
        }

        $opacity = $this->number($raw['opacity'] ?? 1, 0, 1, 1);
        if ($opacity < 1) {
            $element['opacity'] = $opacity;
        }

        foreach (['locked', 'flipX', 'flipY'] as $switch) {
            if (true === ($raw[$switch] ?? null)) {
                $element[$switch] = true;
            }
        }

        $name = $raw['name'] ?? null;
        if (is_string($name) && '' !== mb_trim($name)) {
            $element['name'] = mb_substr(mb_trim($name), 0, 80);
        }

        $group = $raw['group'] ?? null;
        if (is_string($group) && 1 === preg_match('/^[A-Za-z0-9_-]{1,32}$/', $group)) {
            $element['group'] = $group;
        }

        $fill = $this->paint($raw['fill'] ?? null);
        if (null !== $fill) {
            $element['fill'] = $fill;
        }

        $stroke = $this->stroke($raw['stroke'] ?? null);
        if (null !== $stroke) {
            $element['stroke'] = $stroke;
        }

        $radius = $this->number($raw['radius'] ?? 0, 0, 500, 0);
        if ($radius > 0) {
            $element['radius'] = $radius;
        }

        $shadow = $this->shadow($raw['shadow'] ?? null);
        if (null !== $shadow) {
            $element['shadow'] = $shadow;
        }

        $link = $this->link($raw['link'] ?? null);
        if (null !== $link) {
            $element['link'] = $link;
        }

        $enter = $raw['enter'] ?? null;
        if (is_string($enter) && in_array($enter, self::ENTERS, true) && 'none' !== $enter) {
            $element['enter'] = $enter;
            $element['duration'] = (int) $this->number($raw['duration'] ?? 600, 100, 4000, 600);

            $delay = (int) $this->number($raw['delay'] ?? 0, 0, 10000, 0);
            if ($delay > 0) {
                $element['delay'] = $delay;
            }
        }

        $reveal = $raw['reveal'] ?? null;
        if (is_int($reveal) && $reveal > 0) {
            $element['reveal'] = min($reveal, 30);
        }

        return match ($type) {
            'text' => $this->textElement($element, $raw),
            'image' => $this->imageElement($element, $raw),
            'video' => $this->videoElement($element, $raw),
            'embed' => $this->embedElement($element, $raw),
            'shape' => $this->shapeElement($element, $raw),
            'icon' => $this->iconElement($element, $raw),
            'chart' => $this->chartElement($element, $raw),
            'table' => $this->tableElement($element, $raw),
        };
    }

    /**
     * @param array<string, mixed> $element
     * @param array<mixed>         $raw
     *
     * @return array<string, mixed>
     */
    private function textElement(array $element, array $raw): array
    {
        $html = $raw['html'] ?? '';
        $element['html'] = $this->freeTextSanitizer->safe(is_string($html) ? mb_substr($html, 0, self::MAX_HTML) : '');

        // The deck's two families by role, a family from the catalogue by its
        // key, or a font somebody uploaded, by its document. Which keys exist
        // is the editor's business; a key nobody knows falls back to the
        // body family at render, which is a font, not a broken box.
        $font = $raw['font'] ?? null;
        if (is_string($font) && 1 === preg_match('/^(?:heading|body|[a-z0-9-]{1,60}|upload-\d{1,10})$/', $font)) {
            $element['font'] = $font;
        }

        $element['size'] = $this->number($raw['size'] ?? 40, 5, 600, 40);

        $weight = (int) $this->number($raw['weight'] ?? 400, 100, 900, 400);
        $element['weight'] = (int) (round($weight / 100) * 100);

        if (true === ($raw['italic'] ?? null)) {
            $element['italic'] = true;
        }

        $spacing = $this->number($raw['spacing'] ?? 0, -100, 500, 0);
        if (0.0 !== $spacing) {
            $element['spacing'] = $spacing;
        }

        // The space between the box's edge and its words, across and down.
        // What makes a label sit inside its own coloured pill.
        foreach (['padX', 'padY'] as $pad) {
            $amount = $this->number($raw[$pad] ?? 0, 0, 200, 0);

            if ($amount > 0) {
                $element[$pad] = $amount;
            }
        }

        $element['lineHeight'] = $this->number($raw['lineHeight'] ?? 1.2, 0.6, 4, 1.2);
        $element['align'] = $this->oneOf($raw['align'] ?? null, self::ALIGNS, 'left');
        $element['valign'] = $this->oneOf($raw['valign'] ?? null, self::VALIGNS, 'top');

        $case = $this->oneOf($raw['case'] ?? null, self::CASES, 'none');
        if ('none' !== $case) {
            $element['case'] = $case;
        }

        $colour = $this->colour($raw['color'] ?? null);
        if (null !== $colour) {
            $element['color'] = $colour;
        }

        // On unless somebody turned it off: a box whose words fall out of it
        // is the one thing the rest of this module was built to prevent.
        $element['autofit'] = false !== ($raw['autofit'] ?? true);

        return $element;
    }

    /**
     * @param array<string, mixed> $element
     * @param array<mixed>         $raw
     *
     * @return array<string, mixed>
     */
    private function imageElement(array $element, array $raw): array
    {
        $element = $this->framed($element, $raw);

        $focus = $raw['focus'] ?? null;
        if (is_string($focus) && 1 === preg_match('/^\d{1,3}% \d{1,3}%$/', $focus)) {
            $element['focus'] = $focus;
        }

        $zoom = $this->number($raw['zoom'] ?? 1, 1, 5, 1);
        if ($zoom > 1) {
            $element['zoom'] = $zoom;
        }

        $filters = [];
        $asked = is_array($raw['filters'] ?? null) ? $raw['filters'] : [];
        foreach (self::FILTERS as $filter => [$low, $high, $untouched]) {
            if (!array_key_exists($filter, $asked)) {
                continue;
            }

            $amount = (int) $this->number($asked[$filter], $low, $high, $untouched);

            if ($amount !== $untouched) {
                $filters[$filter] = $amount;
            }
        }

        if ([] !== $filters) {
            $element['filters'] = $filters;
        }

        return $element;
    }

    /**
     * @param array<string, mixed> $element
     * @param array<mixed>         $raw
     *
     * @return array<string, mixed>
     */
    private function videoElement(array $element, array $raw): array
    {
        $element = $this->framed($element, $raw);

        foreach (['autoplay', 'loop', 'muted', 'controls'] as $switch) {
            if (true === ($raw[$switch] ?? null)) {
                $element[$switch] = true;
            }
        }

        // A browser plays a film by itself only with its sound off. A film
        // set to start on its own and left with its sound on would simply not
        // start, and nothing would say why.
        if (true === ($element['autoplay'] ?? false)) {
            $element['muted'] = true;
        }

        return $element;
    }

    /**
     * What a picture and a film share: the document, how it fills its box,
     * and what the box is cut to.
     *
     * @param array<string, mixed> $element
     * @param array<mixed>         $raw
     *
     * @return array<string, mixed>
     */
    private function framed(array $element, array $raw): array
    {
        $mediaId = $raw['mediaId'] ?? null;
        if (is_int($mediaId) && $mediaId > 0) {
            $element['mediaId'] = $mediaId;
        }

        $element['fit'] = $this->oneOf($raw['fit'] ?? null, ['cover', 'contain'], 'cover');

        $mask = $this->oneOf($raw['mask'] ?? null, self::MASKS, 'none');
        if ('none' !== $mask) {
            $element['mask'] = $mask;
        }

        return $element;
    }

    /**
     * @param array<string, mixed> $element
     * @param array<mixed>         $raw
     *
     * @return array<string, mixed>
     */
    private function embedElement(array $element, array $raw): array
    {
        // Kept only when it resolves: the frame puts the resolved address in
        // an iframe, and an address that belongs to no known provider would
        // be an author-supplied frame on a public page.
        $url = $raw['url'] ?? null;
        if (null !== $this->videoEmbedResolver->resolve($url) && is_string($url)) {
            $element['url'] = mb_substr(mb_trim($url), 0, 500);
        }

        return $element;
    }

    /**
     * @param array<string, mixed> $element
     * @param array<mixed>         $raw
     *
     * @return array<string, mixed>
     */
    private function shapeElement(array $element, array $raw): array
    {
        $element['shape'] = $this->oneOf($raw['shape'] ?? null, self::SHAPES, 'rect');

        if ('line' === $element['shape']) {
            $element['head'] = $this->oneOf($raw['head'] ?? null, self::HEADS, 'none');
        }

        return $element;
    }

    /**
     * @param array<string, mixed> $element
     * @param array<mixed>         $raw
     *
     * @return array<string, mixed>
     */
    private function iconElement(array $element, array $raw): array
    {
        // A name, not a component: which icons exist is the frame's list, and
        // an unknown one simply draws nothing.
        $icon = $raw['icon'] ?? null;
        $element['icon'] = is_string($icon) && 1 === preg_match('/^[a-z0-9-]{1,40}$/', $icon) ? $icon : 'star';

        $colour = $this->colour($raw['color'] ?? null);
        if (null !== $colour) {
            $element['color'] = $colour;
        }

        $element['strokeWidth'] = $this->number($raw['strokeWidth'] ?? 2, 0.5, 4, 2);

        return $element;
    }

    /**
     * @param array<string, mixed> $element
     * @param array<mixed>         $raw
     *
     * @return array<string, mixed>
     */
    private function chartElement(array $element, array $raw): array
    {
        $element['chartType'] = $this->oneOf($raw['chartType'] ?? null, self::CHART_TYPES, 'bar');
        $element['series'] = $this->lines($raw['series'] ?? null);

        $colour = $this->colour($raw['color'] ?? null);
        if (null !== $colour) {
            $element['color'] = $colour;
        }

        return $element;
    }

    /**
     * @param array<string, mixed> $element
     * @param array<mixed>         $raw
     *
     * @return array<string, mixed>
     */
    private function tableElement(array $element, array $raw): array
    {
        $element['rows'] = $this->lines($raw['rows'] ?? null);
        $element['size'] = $this->number($raw['size'] ?? 22, 5, 200, 22);

        $colour = $this->colour($raw['color'] ?? null);
        if (null !== $colour) {
            $element['color'] = $colour;
        }

        return $element;
    }

    /** @return array<string, mixed>|null */
    private function stroke(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $colour = $this->colour($value['color'] ?? null);
        $width = $this->number($value['width'] ?? 0, 0, 100, 0);

        if (null === $colour || $width <= 0) {
            return null;
        }

        return [
            'color' => $colour,
            'width' => $width,
            'style' => $this->oneOf($value['style'] ?? null, self::STROKE_STYLES, 'solid'),
        ];
    }

    /** @return array<string, mixed>|null */
    private function shadow(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $colour = $this->colour($value['color'] ?? null);

        if (null === $colour) {
            return null;
        }

        return [
            'x' => $this->number($value['x'] ?? 0, -200, 200, 0),
            'y' => $this->number($value['y'] ?? 8, -200, 200, 8),
            'blur' => $this->number($value['blur'] ?? 20, 0, 400, 20),
            'color' => $colour,
        ];
    }

    /**
     * Where a click on the element takes the reader, when it takes them
     * anywhere. The same schemes as an article's links, minus the relative
     * ones: a deck is opened from a share link, where `/` is somebody else's.
     */
    private function link(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $url = mb_trim($value);

        foreach (['https://', 'http://', 'mailto:'] as $prefix) {
            if (str_starts_with(mb_strtolower($url), $prefix)) {
                return mb_substr($url, 0, 500);
            }
        }

        return null;
    }

    /**
     * A list of lines, as the chart and the table store them.
     *
     * @return list<string>
     */
    private function lines(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $lines = array_values(array_filter(
            $value,
            is_string(...),
        ));

        return array_slice(
            array_map(static fn (string $line): string => mb_substr($line, 0, 300), $lines),
            0,
            self::MAX_LIST_LINES,
        );
    }

    /**
     * A number, clamped, rounded to what a person can tell apart.
     *
     * Clamped rather than refused: every one of these comes from a handle or a
     * slider bounded at both ends, so a value outside is a payload edited by
     * hand, and the nearest edge is a better answer than a box that vanishes.
     */
    private function number(mixed $value, float $low, float $high, float $default): float
    {
        if (is_bool($value) || (!is_int($value) && !is_float($value))) {
            return $default;
        }

        return round(max($low, min($high, (float) $value)), 3);
    }

    /** An angle in degrees, folded into one turn either side of zero. */
    private function angle(mixed $value): float
    {
        if (is_bool($value) || (!is_int($value) && !is_float($value))) {
            return 0.0;
        }

        $folded = fmod((float) $value, 360.0);

        if ($folded > 180) {
            $folded -= 360;
        } elseif ($folded <= -180) {
            $folded += 360;
        }

        return round($folded, 2);
    }

    /** @param list<string> $allowed */
    private function oneOf(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    private function newId(): string
    {
        return 'e'.bin2hex(random_bytes(6));
    }
}
