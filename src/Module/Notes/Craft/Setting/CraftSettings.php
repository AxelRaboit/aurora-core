<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Craft\Setting;

use Aurora\Core\Encryption\Service\EncryptionServiceInterface;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use SensitiveParameter;

use function mb_rtrim;
use function mb_trim;
use function str_starts_with;

/**
 * Whether this installation can read a Craft space, and which one.
 *
 * **Off until someone turns it on**, like the other integrations: Aurora is
 * delivered to clients, and the account behind the token belongs to the
 * person who installs it, not to me.
 *
 * **The connection only carries the chosen documents.** Craft offers both:
 * the whole space, or a selection. An Aurora installation lives on a rented
 * server, and a token sleeping there must not open the whole of a body of
 * personal knowledge so that a brief can land in a note. So it is in Craft
 * that you designate what Aurora is allowed to see, and the import screen
 * shows nothing else.
 *
 * The token is encrypted at rest with {@see EncryptionServiceInterface}, like
 * the mount point passwords: a database dump stays a database dump.
 */
final readonly class CraftSettings
{
    public function __construct(
        private SettingRepository $settingRepository,
        private EncryptionServiceInterface $encryption,
    ) {}

    /**
     * The only question the rest of the code asks.
     *
     * All three conditions every time: an address without a token answers
     * nothing, a token without an address goes nowhere.
     */
    public function isEnabled(): bool
    {
        return $this->settingRepository->getBoolean(CraftSettingEnum::Enabled->value)
            && '' !== $this->endpoint()
            && '' !== $this->token();
    }

    /**
     * The address, without a trailing slash.
     *
     * Craft gives it as is and paths are appended to it: normalizing it here
     * avoids the `//documents` that an address copied with its slash produces
     * one time out of two.
     */
    public function endpoint(): string
    {
        $stored = mb_trim((string) $this->settingRepository->get(CraftSettingEnum::Endpoint->value, ''));

        if ('' === $stored || !str_starts_with($stored, 'https://')) {
            // A plain-text address refused rather than corrected: a token
            // sent over HTTP is a token read by whoever holds the network.
            return '';
        }

        return mb_rtrim($stored, '/');
    }

    public function token(): string
    {
        $stored = (string) $this->settingRepository->get(CraftSettingEnum::Token->value, '');

        if ('' === $stored) {
            return '';
        }

        // A token written before a rotation of the encryption key decrypts
        // to null. Treated as absent rather than fatal: the integration goes
        // quiet and the tab asks for the token again, which is the only
        // thing that fixes it.
        return $this->encryption->decrypt($stored) ?? '';
    }

    /**
     * What the settings screen is allowed to know.
     *
     * `hasToken` and not the token: the browser must draw "a token is
     * saved", nothing more.
     *
     * @return array{enabled: bool, endpoint: string, hasToken: bool}
     */
    public function state(): array
    {
        return [
            'enabled' => $this->settingRepository->getBoolean(CraftSettingEnum::Enabled->value),
            'endpoint' => $this->endpoint(),
            'hasToken' => '' !== $this->token(),
        ];
    }

    /**
     * @param string|null $token null leaves the saved token alone -
     *                           that is how a form that never received it
     *                           saves the rest of the tab. An empty
     *                           string is an explicit "forget it".
     */
    public function save(
        bool $enabled,
        string $endpoint,
        #[SensitiveParameter]
        ?string $token,
    ): void {
        $entries = [[CraftSettingEnum::Endpoint->value, '' === mb_trim($endpoint) ? null : mb_trim($endpoint)]];

        if (null !== $token) {
            $entries[] = [
                CraftSettingEnum::Token->value,
                '' === $token ? null : $this->encryption->encrypt($token),
            ];
        }

        $entries[] = [CraftSettingEnum::Enabled->value, $enabled ? '1' : '0'];

        $this->settingRepository->saveMany($entries);
    }
}
