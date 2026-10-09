<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Notes\Live;

use Aurora\Module\Notes\Live\Service\NoteGuestIdentity;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * A guest's identity is the server's, and stays above every account.
 *
 * What is under test is the one way a page could try to choose its own id - a
 * hand-written cookie - and the range that keeps a guest from ever being taken
 * for a colleague, or elected before one.
 */
final class NoteGuestIdentityTest extends TestCase
{
    public function testAnIssuedIdIsAGuestId(): void
    {
        $identity = new NoteGuestIdentity();

        for ($attempt = 0; $attempt < 50; ++$attempt) {
            self::assertTrue(NoteGuestIdentity::isGuestId($identity->issue()));
        }
    }

    public function testAGuestIdIsReadBackFromItsCookie(): void
    {
        $request = new Request(cookies: [NoteGuestIdentity::COOKIE_NAME => (string) (NoteGuestIdentity::FLOOR + 42)]);

        self::assertSame(NoteGuestIdentity::FLOOR + 42, new NoteGuestIdentity()->idFrom($request));
    }

    public function testACookieNamingAnAccountIsIgnored(): void
    {
        $request = new Request(cookies: [NoteGuestIdentity::COOKIE_NAME => '2']);

        self::assertNull(new NoteGuestIdentity()->idFrom($request));
    }

    public function testACookieThatIsNotANumberIsIgnored(): void
    {
        foreach (['', 'abc', '-1500000000', '1500000000.5', '99999999999'] as $value) {
            $request = new Request(cookies: [NoteGuestIdentity::COOKIE_NAME => $value]);

            self::assertNull(new NoteGuestIdentity()->idFrom($request), $value);
        }
    }

    public function testNoCookieIsNoGuest(): void
    {
        self::assertNull(new NoteGuestIdentity()->idFrom(new Request()));
    }
}
