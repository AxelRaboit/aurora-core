<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Core\Sequence;

use Aurora\Core\Sequence\SequenceGenerator;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Connection;

use function array_unique;
use function bin2hex;
use function mb_strtoupper;
use function random_bytes;

/**
 * Reserving several numbers at once hands out the same numbers one at a time
 * would have: consecutive, never twice, and the next single one follows on.
 */
final class SequenceGeneratorTest extends IntegrationTestCase
{
    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();
        $this->prefix = 'T'.mb_strtoupper(bin2hex(random_bytes(3)));
    }

    protected function tearDown(): void
    {
        static::getContainer()->get(Connection::class)->executeStatement('DELETE FROM app_sequence_counters WHERE prefix = ?', [$this->prefix]);
        parent::tearDown();
    }

    public function testAReservationIsConsecutiveAndTheNextNumberFollowsIt(): void
    {
        $generator = static::getContainer()->get(SequenceGenerator::class);

        $first = $generator->next($this->prefix);
        $batch = $generator->nextMany($this->prefix, 3);
        $after = $generator->next($this->prefix);

        self::assertSame($this->prefix.'-000001', $first);
        self::assertSame([$this->prefix.'-000002', $this->prefix.'-000003', $this->prefix.'-000004'], $batch);
        self::assertSame($this->prefix.'-000005', $after);
    }

    public function testAFirstReservationStartsTheSeriesAtOne(): void
    {
        $batch = static::getContainer()->get(SequenceGenerator::class)->nextMany($this->prefix, 2);

        self::assertSame([$this->prefix.'-000001', $this->prefix.'-000002'], $batch);
        self::assertCount(2, array_unique($batch));
    }

    public function testNothingIsReservedForAnEmptyBatch(): void
    {
        $generator = static::getContainer()->get(SequenceGenerator::class);

        self::assertSame([], $generator->nextMany($this->prefix, 0));
        self::assertSame(0, $generator->current($this->prefix));
    }
}
