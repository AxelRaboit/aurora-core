<?php

declare(strict_types=1);

namespace Aurora\Module\Beacon\Service;

use Aurora\Module\Beacon\Setting\BeaconSettingEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;

use function in_array;
use function is_array;
use function is_string;
use function mb_strtolower;
use function mb_trim;

/**
 * The allowlist that tells your own deployments from a copy. Stored as a JSON
 * setting so it stays editable from the back-office later; until it is set, it
 * falls back to the built-in defaults below. Nothing here rejects a ping - an
 * unknown domain is exactly what we want recorded - it only decides the
 * known/unknown flag (see LICENSE).
 */
final readonly class KnownDomains
{
    /** @var list<string> */
    private const array DEFAULTS = ['axelraboit.fr', 'app.axelraboit.fr'];

    public function __construct(private SettingRepository $settings) {}

    public function contains(string $domain): bool
    {
        return in_array(mb_strtolower(mb_trim($domain)), $this->all(), true);
    }

    /** @return list<string> */
    public function all(): array
    {
        $raw = $this->settings->get(BeaconSettingEnum::KnownDomains->value);
        if (null === $raw || '' === $raw) {
            return self::DEFAULTS;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return self::DEFAULTS;
        }

        $domains = [];
        foreach ($decoded as $entry) {
            if (is_string($entry) && '' !== mb_trim($entry)) {
                $domains[] = mb_strtolower(mb_trim($entry));
            }
        }

        return [] === $domains ? self::DEFAULTS : $domains;
    }
}
