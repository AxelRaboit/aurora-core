<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Share;

use function array_slice;
use function in_array;
use function is_array;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function mb_trim;
use function preg_match;

/**
 * The share links of a publication, as the editor may send them.
 *
 * Null means "never configured", and the page then shows the default row:
 * that is what keeps every publication written before this existed exactly as
 * it was. An empty list is a choice, and shows nothing.
 *
 * A custom link's address ends up in the `href` of a public page, so it is
 * held to `https://` and `mailto:` here, at the write boundary: a
 * `javascript:` address typed in the editor would otherwise run in every
 * visitor's browser.
 */
final class ShareLinksNormalizer
{
    public const array TYPES = ['copy', 'linkedin', 'whatsapp', 'x', 'facebook', 'bluesky', 'email', 'custom'];

    public const int MAX_LINKS = 12;

    private const int MAX_LABEL = 60;

    private const int MAX_URL = 500;

    /**
     * @return list<array{type: string, label: ?string, url: ?string, color: ?string}>|null
     */
    public function normalize(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $links = [];
        foreach ($raw as $entry) {
            $link = is_array($entry) ? $this->link($entry) : null;
            if (null !== $link) {
                $links[] = $link;
            }
        }

        return array_slice($links, 0, self::MAX_LINKS);
    }

    /**
     * @param array<mixed> $entry
     *
     * @return array{type: string, label: ?string, url: ?string, color: ?string}|null
     */
    private function link(array $entry): ?array
    {
        $type = $entry['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::TYPES, true)) {
            return null;
        }

        $url = null;
        if ('custom' === $type) {
            $url = $this->url($entry['url'] ?? null);
            // A custom link without a usable address is a button to nowhere.
            if (null === $url) {
                return null;
            }
        }

        return [
            'type' => $type,
            'label' => $this->label($entry['label'] ?? null),
            'url' => $url,
            'color' => $this->color($entry['color'] ?? null),
        ];
    }

    private function label(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $label = mb_trim($raw);

        return '' === $label ? null : mb_substr($label, 0, self::MAX_LABEL);
    }

    private function url(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $url = mb_trim($raw);

        if ('' === $url || mb_strlen($url) > self::MAX_URL || 1 !== preg_match('#^(https://|mailto:)\S+$#i', $url)) {
            return null;
        }

        return $url;
    }

    private function color(mixed $raw): ?string
    {
        return is_string($raw) && 1 === preg_match('/^#[0-9a-fA-F]{6}$/', $raw) ? $raw : null;
    }
}
