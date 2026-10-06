<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Studio\Contract\Signature\Entity;

use Aurora\Module\Studio\Contract\Signature\Entity\AbstractContractSignatureChallenge;
use Aurora\Module\Studio\Contract\Signature\Entity\ContractSignatureChallenge;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

use function preg_match;

/**
 * Six digits is a million possibilities, and none of that million matters if
 * the limits are missing.
 *
 * These tests are about the limits rather than the code: the length is what it
 * is because somebody has to type it on a phone, and the expiry, the attempt
 * ceiling and the single use are what make it enough.
 */
final class ContractSignatureChallengeTest extends TestCase
{
    public function testAMintedCodeIsSixDigitsAndOnlyItsHashIsKept(): void
    {
        $challenge = new ContractSignatureChallenge();
        $code = $challenge->mint();

        self::assertSame(1, preg_match('/^\d{6}$/', $code));
        // The stored form is a digest, not the code. A code readable in a
        // database dump would let somebody sign without seeing the mailbox.
        self::assertNotSame($code, $challenge->getHashedCode());
        self::assertSame(64, mb_strlen($challenge->getHashedCode()));
        self::assertSame(AbstractContractSignatureChallenge::hashCode($code), $challenge->getHashedCode());
    }

    /**
     * A code starting with a zero is still six characters.
     *
     * Padded rather than formatted at display time: the person typing it counts
     * the characters, and a five-character code reads as a truncated one.
     */
    public function testACodeIsAlwaysSixCharactersLong(): void
    {
        for ($draw = 0; $draw < 200; ++$draw) {
            $code = new ContractSignatureChallenge()->mint();

            self::assertSame(6, mb_strlen($code));
        }
    }

    public function testAFreshCodeIsUsableAndExpiresOnItsOwn(): void
    {
        $challenge = new ContractSignatureChallenge();
        $challenge->mint();

        $now = new DateTimeImmutable();

        self::assertTrue($challenge->isUsable($now));
        self::assertFalse($challenge->isExpired($now));

        $later = $now->modify(sprintf('+%d minutes', AbstractContractSignatureChallenge::LIFETIME_MINUTES + 1));

        self::assertTrue($challenge->isExpired($later));
        self::assertFalse($challenge->isUsable($later));
    }

    public function testFiveWrongAnswersKillTheCode(): void
    {
        $challenge = new ContractSignatureChallenge();
        $challenge->mint();

        for ($attempt = 0; $attempt < AbstractContractSignatureChallenge::MAX_ATTEMPTS - 1; ++$attempt) {
            $challenge->recordFailedAttempt();
            self::assertTrue($challenge->hasAttemptsLeft());
        }

        $challenge->recordFailedAttempt();

        // The limit is what makes six digits enough: without it a script walks
        // the million.
        self::assertFalse($challenge->hasAttemptsLeft());
        self::assertFalse($challenge->isUsable(new DateTimeImmutable()));
    }

    public function testAConsumedCodeIsDeadInsideItsOwnWindow(): void
    {
        $challenge = new ContractSignatureChallenge();
        $challenge->mint();

        $now = new DateTimeImmutable();
        $challenge->consume($now);

        self::assertTrue($challenge->isConsumed());
        // Not expired, not out of attempts, and still refused: single use is
        // what makes "the code was verified" mean one act.
        self::assertFalse($challenge->isExpired($now));
        self::assertTrue($challenge->hasAttemptsLeft());
        self::assertFalse($challenge->isUsable($now));
    }

    /**
     * Consuming twice keeps the first moment.
     *
     * The same rule as revoking a link: the second call is a double click, and
     * moving the timestamp would rewrite when the code was actually used.
     */
    public function testConsumingTwiceKeepsTheFirstMoment(): void
    {
        $challenge = new ContractSignatureChallenge();
        $challenge->mint();

        $first = new DateTimeImmutable('2026-09-09 10:00:00');
        $challenge->consume($first);
        $challenge->consume(new DateTimeImmutable('2026-09-09 11:00:00'));

        self::assertSame($first, $challenge->getConsumedAt());
    }

    public function testTwoCodesAreNotTheSame(): void
    {
        $codes = [];

        for ($draw = 0; $draw < 50; ++$draw) {
            $codes[] = new ContractSignatureChallenge()->mint();
        }

        // Not a randomness test, which a unit test cannot do: a guard against
        // a mint that returns a constant, which is the failure that would
        // otherwise pass every other test here.
        self::assertGreaterThan(1, count(array_unique($codes)));
    }
}
