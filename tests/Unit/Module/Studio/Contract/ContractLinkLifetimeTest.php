<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Studio\Contract;

use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Studio\Contract\Service\ContractLinkLifetime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The setting decides how long a signing address lives, within bounds: an
 * emptied or mistyped field must neither kill the link on arrival nor make it
 * last for ever.
 */
final class ContractLinkLifetimeTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function settings(): iterable
    {
        yield 'the default' => ['30', 30];
        yield 'a week' => ['7', 7];
        yield 'emptied' => ['', 1];
        yield 'zero' => ['0', 1];
        yield 'negative' => ['-5', 1];
        yield 'a decade' => ['3650', 365];
    }

    #[DataProvider('settings')]
    public function testTheSettingIsReadWithinItsBounds(string $stored, int $expected): void
    {
        $settings = $this->createStub(SettingRepository::class);
        $settings->method('getOrDefault')->willReturn($stored);

        self::assertSame($expected, new ContractLinkLifetime($settings)->days());
    }
}
