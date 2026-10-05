<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Sharing;

use function is_int;
use function is_string;
use function mb_strlen;
use function mb_trim;

/**
 * What every Studio reading link obeys, whoever hands it out.
 *
 * A deliverable, a presentation and a contract access are three ways to give a
 * document to someone without an account. They used to differ on the details
 * that matter most: how long an address may live, how long its password may be,
 * and what becomes of a link once it has been used. One place now, so a module
 * cannot quietly be the lax one.
 *
 * - An address lasts from 1 to {@see self::MAX_EXPIRY_DAYS} days, or never
 *   (absent or null). A duration that cannot be read is refused, never turned
 *   into "never": the sender typed one on purpose.
 * - A password is at most {@see self::PASSWORD_MAX_BYTES} BYTES: bcrypt reads no
 *   further, so past it two different phrases would open the same link.
 * - A retired or expired link may be hidden from the list, never a live one.
 * - A link is revocable at any time, and its row survives (who could read it,
 *   and how often it was opened, is worth knowing afterwards). It may be
 *   DELETED only while it has never been opened: there is then nothing to
 *   remember, and a mistyped address should not stay in the list forever.
 */
final class ShareLinkRules
{
    /** A year: past it, an expiry date protects very little. */
    public const int MAX_EXPIRY_DAYS = 365;

    /** What bcrypt, chosen by `PASSWORD_DEFAULT`, takes into account. */
    public const int PASSWORD_MAX_BYTES = 72;

    /** The payload field carrying the password, and the key its error is filed under. */
    private const string PASSWORD_FIELD = 'password';

    private function __construct() {}

    /** True for "never" (null) and for a whole number of days within the limit. */
    public static function expiryIsValid(mixed $days): bool
    {
        return null === $days || (is_int($days) && $days >= 1 && $days <= self::MAX_EXPIRY_DAYS);
    }

    /**
     * The password as it is kept and as it is compared: without the edge
     * spaces, on both sides.
     *
     * @param array<string, mixed> $payload
     */
    public static function password(array $payload): string
    {
        return is_string($payload['password'] ?? null) ? mb_trim($payload['password']) : '';
    }

    /** Bytes, not letters: "é" weighs two, and bcrypt counts bytes. */
    public static function passwordIsTooLong(string $phrase): bool
    {
        return mb_strlen($phrase, '8bit') > self::PASSWORD_MAX_BYTES;
    }

    /**
     * What creating a link would refuse in this payload.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, string> translation keys by field, empty when everything passes
     */
    public static function errors(array $payload): array
    {
        $errors = [];

        if (!self::expiryIsValid($payload['expiresInDays'] ?? null)) {
            $errors['expiresInDays'] = 'backend.studio.sharing.errors.expiry_invalid';
        }

        if (self::passwordIsTooLong(self::password($payload))) {
            $errors[self::PASSWORD_FIELD] = 'backend.studio.sharing.errors.password_too_long';
        }

        return $errors;
    }

    /**
     * Only a link that no longer opens anything (retired or expired) may be
     * hidden from the list: a live one has to be retired first, or hiding it
     * would leave an address working that its author can no longer see.
     */
    public static function canBeHidden(bool $usable): bool
    {
        return !$usable;
    }

    /** Only a link nobody ever opened may be deleted; any other is revoked. */
    public static function canBeDeleted(int $openCount): bool
    {
        return 0 === $openCount;
    }
}
