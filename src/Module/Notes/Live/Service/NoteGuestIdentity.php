<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Live\Service;

use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

use function random_int;

/**
 * Who a visitor holding a writing link is, for as long as they keep their
 * browser.
 *
 * **Issued by the server, never chosen by the page.** Writing a note together
 * needs everybody in the room to have an id: the elections compare them, the
 * carets are keyed on them, and a page drops itself from the room by them. A
 * guest has no account to lend one, so this hands them a number and keeps it
 * in a cookie that only the share routes ever see.
 *
 * **Above every account, by construction.** The ids start at a billion, and an
 * installation will not have that many accounts. Two things follow without
 * any further rule. A guest can never be mistaken for a colleague, nor take
 * their caret or their place in the room. And since the room elects its lowest
 * id, **an account is always elected before a guest**: whenever somebody with an
 * account is on the note, the write-back goes through their ordinary save,
 * with its three-way merge - and the guest route only ever writes for a room
 * of guests.
 *
 * A cookie that comes back with a number outside that range is ignored and a
 * new one is issued: a hand-written cookie is the one way a page could try to
 * choose its own id.
 */
final readonly class NoteGuestIdentity
{
    public const string COOKIE_NAME = 'aurora_note_guest';

    /** The first id a guest can have. */
    public const int FLOOR = 1_000_000_000;

    /** The last one: below 2^31, so it fits every integer column and JavaScript. */
    public const int CEILING = 1_999_999_999;

    /** Kept a year: the same visitor coming back is the same guest in the room. */
    private const int COOKIE_LIFETIME_DAYS = 365;

    public static function isGuestId(int $id): bool
    {
        return $id >= self::FLOOR && $id <= self::CEILING;
    }

    /** The guest the request already carries, or null when it carries none worth trusting. */
    public function idFrom(Request $request): ?int
    {
        $value = $request->cookies->get(self::COOKIE_NAME);

        if (!is_string($value) || !ctype_digit($value)) {
            return null;
        }

        $id = (int) $value;

        return self::isGuestId($id) ? $id : null;
    }

    public function issue(): int
    {
        return random_int(self::FLOOR, self::CEILING);
    }

    /**
     * The cookie that keeps the guest the same person from one beat to the next.
     *
     * Scoped to the share routes and http-only: the page never needs to read
     * it, since the beat tells it its own id, and no other route has any use
     * for it.
     */
    public function cookieFor(int $id, string $path): Cookie
    {
        return Cookie::create(
            self::COOKIE_NAME,
            (string) $id,
            new DateTimeImmutable('+'.self::COOKIE_LIFETIME_DAYS.' days'),
            $path,
            null,
            secure: null,
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }
}
