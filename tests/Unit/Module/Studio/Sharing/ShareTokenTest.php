<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Studio\Sharing;

use Aurora\Module\Studio\Sharing\ShareToken;
use PHPUnit\Framework\TestCase;

use function hash;
use function preg_match;

final class ShareTokenTest extends TestCase
{
    public function testAGeneratedTokenIsWhatTheRoutesAccept(): void
    {
        $token = ShareToken::generate();

        self::assertTrue(ShareToken::isWellFormed($token));
        self::assertSame(1, preg_match('/^'.ShareToken::PATTERN.'$/', $token));
        self::assertNotSame($token, ShareToken::generate());
    }

    public function testTheHashIsAOneWayFingerprintOfTheToken(): void
    {
        $token = ShareToken::generate();

        self::assertSame(hash('sha256', $token), ShareToken::hash($token));
        self::assertNotSame($token, ShareToken::hash($token));
        self::assertTrue(ShareToken::isWellFormed(ShareToken::hash($token)), 'Same shape, so it can fill the same column width.');
    }

    public function testWhatIsNotATokenIsRefused(): void
    {
        $valid = str_repeat('a', 64);

        foreach (['', 'abc', str_repeat('A', 64), str_repeat('g', 64), str_repeat('a', 63), str_repeat('a', 65), $valid."\n", ' '.$valid] as $value) {
            self::assertFalse(ShareToken::isWellFormed($value), $value);
        }

        self::assertTrue(ShareToken::isWellFormed($valid));
    }
}
