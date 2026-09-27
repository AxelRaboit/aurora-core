<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Share;

use Aurora\Module\Configuration\Setting\Repository\SettingRepository;

use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * The useful links of the whole site, set once in Configuration and shown at
 * the foot of every publication that does not bring its own.
 *
 * Kept in one `core_settings` row as JSON, and read back through
 * {@see UsefulLinksNormalizer} rather than trusted: the value ends up in the
 * `href` of every public page, so a row edited by hand is held to the same
 * rules as a link typed in the editor.
 */
final readonly class SiteUsefulLinks
{
    public const string KEY = 'editorial_useful_links';

    public function __construct(
        private SettingRepository $settingRepository,
    ) {}

    /**
     * @return list<array{label: string, url: string, color: ?string}>
     */
    public function links(): array
    {
        $raw = $this->settingRepository->get(self::KEY, null);

        if (!is_string($raw) || '' === $raw) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return new UsefulLinksNormalizer()->normalize($decoded) ?? [];
    }

    /**
     * @return list<array{label: string, url: string, color: ?string}>
     */
    public function save(mixed $raw): array
    {
        $links = new UsefulLinksNormalizer()->normalize($raw) ?? [];

        $this->settingRepository->set(self::KEY, json_encode($links, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $links;
    }
}
