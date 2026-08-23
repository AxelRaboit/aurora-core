<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Service;

use stdClass;

/**
 * Builds Editor.js blocks in the shape the editor can actually open.
 *
 * Writing block JSON by hand is how content ends up rendering on the public
 * site while the backend shows "The block can not be displayed correctly":
 * the server-side renderer is forgiving - it accepts every shape a tool has
 * ever emitted - and the tool itself is not. A list written as
 * `items: [{content: 'x'}]` is neither the old shape (`items: ['x']`) nor the
 * current one (`{content, meta, items}`), so it reads on the site and breaks
 * in the editor, which is the worst way round: nobody notices until an editor
 * opens the post.
 *
 * These helpers exist so fixtures and any code seeding content programmatically
 * write what `@editorjs/*` expects, in one place that can be corrected when a
 * tool changes its format.
 */
final class EditorBlocks
{
    /** @return array{type: string, data: array<string, mixed>} */
    public static function header(string $text, int $level = 2): array
    {
        return ['type' => 'header', 'data' => ['text' => $text, 'level' => $level]];
    }

    /** @return array{type: string, data: array<string, mixed>} */
    public static function paragraph(string $text): array
    {
        return ['type' => 'paragraph', 'data' => ['text' => $text]];
    }

    /**
     * `meta` is an object, not an array - `[]` encodes to `[]` in JSON and the
     * tool expects `{}`. Every item carries its own `meta` and `items` too:
     * the tool reads them unconditionally when the list is not in its legacy
     * string form.
     *
     * @param list<string> $items
     *
     * @return array{type: string, data: array<string, mixed>}
     */
    public static function list(array $items, string $style = 'unordered'): array
    {
        return ['type' => 'list', 'data' => [
            'style' => $style,
            'meta' => new stdClass(),
            'items' => array_map(
                static fn (string $item): array => [
                    'content' => $item,
                    'meta' => new stdClass(),
                    'items' => [],
                ],
                $items,
            ),
        ]];
    }

    /** @return array{type: string, data: array<string, mixed>} */
    public static function quote(string $text, string $caption = ''): array
    {
        return ['type' => 'quote', 'data' => ['text' => $text, 'caption' => $caption]];
    }

    /** @return array{type: string, data: array<string, mixed>} */
    public static function image(string $url, string $caption = ''): array
    {
        return ['type' => 'image', 'data' => ['file' => ['url' => $url], 'caption' => $caption]];
    }

    /** @return array{type: string, data: array<string, mixed>} */
    public static function mediaText(string $imageUrl, string $html): array
    {
        return ['type' => 'mediaText', 'data' => ['image' => ['url' => $imageUrl], 'text' => $html]];
    }

    /**
     * `message`, not `text`: the tool saves `{type, title, message}`, and a
     * callout written the other way opens blank in the backend.
     *
     * @return array{type: string, data: array<string, mixed>}
     */
    public static function callout(string $message, string $type = 'info', string $title = ''): array
    {
        return ['type' => 'callout', 'data' => ['type' => $type, 'title' => $title, 'message' => $message]];
    }

    /** @return array{type: string, data: array<string, mixed>} */
    public static function delimiter(): array
    {
        return ['type' => 'delimiter', 'data' => []];
    }
}
