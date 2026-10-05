<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Studio\Sharing;

use Aurora\Module\Studio\Sharing\ShareLinkRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

final class ShareLinkRulesTest extends TestCase
{
    /** @return iterable<string, array{mixed, bool}> */
    public static function durations(): iterable
    {
        yield 'never' => [null, true];
        yield 'one day' => [1, true];
        yield 'a year' => [365, true];
        yield 'past a year' => [366, false];
        yield 'zero is not "never"' => [0, false];
        yield 'negative' => [-3, false];
        yield 'a decimal' => [7.5, false];
        yield 'text' => ['30', false];
        yield 'a boolean' => [true, false];
    }

    #[DataProvider('durations')]
    public function testADurationIsAWholeNumberOfDaysUpToAYearOrNothing(mixed $days, bool $valid): void
    {
        self::assertSame($valid, ShareLinkRules::expiryIsValid($days));
        self::assertSame($valid ? [] : ['expiresInDays' => 'suite.studio.sharing.errors.expiry_invalid'], ShareLinkRules::errors(['expiresInDays' => $days]));
    }

    public function testAnAbsentDurationMeansNever(): void
    {
        self::assertSame([], ShareLinkRules::errors([]));
    }

    public function testAPasswordIsCountedInBytesAfterTrimming(): void
    {
        self::assertSame([], ShareLinkRules::errors(['password' => str_repeat('a', 72)]));
        self::assertSame([], ShareLinkRules::errors(['password' => '  '.str_repeat('a', 72).'  ']), 'the edge spaces are not part of it');
        self::assertArrayHasKey('password', ShareLinkRules::errors(['password' => str_repeat('a', 73)]));
        // 37 letters of two bytes each: 37 characters, 74 bytes.
        self::assertArrayHasKey('password', ShareLinkRules::errors(['password' => str_repeat('é', 37)]));
        self::assertSame([], ShareLinkRules::errors(['password' => str_repeat('é', 36)]));
    }

    public function testThePasswordIsKeptTrimmedAndEmptyWhenNotAString(): void
    {
        self::assertSame('secret', ShareLinkRules::password(['password' => "  secret \n"]));
        self::assertSame('', ShareLinkRules::password(['password' => 1234]));
        self::assertSame('', ShareLinkRules::password([]));
    }

    public function testOnlyALinkNobodyOpenedMayBeDeleted(): void
    {
        self::assertTrue(ShareLinkRules::canBeDeleted(0));
        self::assertFalse(ShareLinkRules::canBeDeleted(1));
        self::assertFalse(ShareLinkRules::canBeDeleted(40));
    }

    public function testOnlyALinkThatOpensNothingMayBeHidden(): void
    {
        self::assertTrue(ShareLinkRules::canBeHidden(false));
        self::assertFalse(ShareLinkRules::canBeHidden(true));
    }
}
