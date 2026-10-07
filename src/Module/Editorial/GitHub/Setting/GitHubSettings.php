<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\GitHub\Setting;

use Aurora\Module\Configuration\Setting\Repository\SettingRepository;

use function array_slice;
use function implode;
use function mb_strtolower;
use function mb_substr;
use function preg_match;
use function preg_split;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Which GitHub accounts the "Activité GitHub" zone displays.
 *
 * **Off until someone turns it on**, like the other integrations: Aurora is
 * delivered to clients, and the accounts displayed are those of the person
 * who installs it. Nothing is asked of GitHub before that.
 *
 * No key: the grid is read on the public page of a profile, which any
 * visitor can open. There is therefore nothing secret here, and nothing to
 * encrypt.
 */
final readonly class GitHubSettings
{
    /**
     * Beyond this, the zone turns into a wall of grids, and each account is one
     * more request when the cache renews.
     */
    public const int MAX_LOGINS = 4;

    public function __construct(
        private SettingRepository $settingRepository,
    ) {}

    /** Turned on, and at least one account to show. */
    public function isEnabled(): bool
    {
        return $this->settingRepository->getBoolean(GitHubSettingEnum::Enabled->value)
            && [] !== $this->logins();
    }

    /**
     * The saved identifiers, in display order.
     *
     * Read back through {@see self::parse()}: a row written by hand in the
     * database, or before a stricter rule, must never compose an address.
     *
     * @return list<string>
     */
    public function logins(): array
    {
        [$valid] = self::parse((string) $this->settingRepository->get(GitHubSettingEnum::Logins->value, ''));

        return array_slice($valid, 0, self::MAX_LOGINS);
    }

    /**
     * Splits a free input into identifiers.
     *
     * A line break, a comma or a space separates; a leading `@` is tolerated,
     * since that is how an account is written everywhere else. Duplicates are
     * ignored case-insensitively, as GitHub treats them.
     *
     * No cap: it is up to whoever saves to refuse a list that is too long, and
     * up to whoever reads never to display more than {@see self::MAX_LOGINS}.
     *
     * @return array{0: list<string>, 1: list<string>} the valid ones, then the refused ones
     */
    public static function parse(string $input): array
    {
        $valid = [];
        $invalid = [];
        $seen = [];

        foreach (preg_split('/[\s,;]+/u', $input, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $login = '@' === $token[0] ? mb_substr($token, 1) : $token;

            // GitHub's rules: letters, digits and single hyphens, no hyphen at the
            // edges, 39 characters at most.
            if (1 !== preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}$/', $login)) {
                $invalid[] = $token;

                continue;
            }

            $key = mb_strtolower($login);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $valid[] = $login;
        }

        return [$valid, $invalid];
    }

    /**
     * @return array{enabled: bool, logins: list<string>, max: int}
     */
    public function state(): array
    {
        return [
            'enabled' => $this->settingRepository->getBoolean(GitHubSettingEnum::Enabled->value),
            'logins' => $this->logins(),
            'max' => self::MAX_LOGINS,
        ];
    }

    /**
     * @param list<string> $logins already run through {@see self::parse()}
     */
    public function save(bool $enabled, array $logins): void
    {
        $this->settingRepository->saveMany([
            [GitHubSettingEnum::Logins->value, [] === $logins ? null : implode("\n", $logins)],
            [GitHubSettingEnum::Enabled->value, $enabled ? '1' : '0'],
        ]);
    }
}
