<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Twig;

use Aurora\Core\Twig\FileSizeExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\LocaleAwareInterface;

/**
 * Holds the filter to the values `useFileSize.test.js` asserts of its Vue
 * counterpart.
 *
 * The cases below are that test's, copied deliberately rather than referenced:
 * the point is that two implementations agree, and a shared fixture would only
 * prove they read the same file. A reader who is shown "1.0 Mo" in the library
 * and "1.05 MB" on the page they published is being shown two products.
 */
final class FileSizeExtensionTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function sizes(): iterable
    {
        yield 'bytes stay whole' => [512, 'fr', '512 o'];
        yield 'a kilobyte' => [1024, 'fr', '1.0 Ko'];
        yield 'a megabyte' => [1024 ** 2, 'fr', '1.0 Mo'];
        yield 'a gigabyte, to two decimals' => [1024 ** 3, 'fr', '1.00 Go'];
        yield 'english keeps the byte' => [1024, 'en', '1.0 KB'];
        yield 'spanish too' => [1024 ** 2, 'es', '1.0 MB'];
    }

    #[DataProvider('sizes')]
    public function testItSpellsASizeTheWayTheLanguageDoes(int $bytes, string $locale, string $expected): void
    {
        self::assertSame($expected, $this->extension($locale)->fileSize($bytes));
    }

    /** A language nobody wrote a table for reads as English rather than crashing. */
    public function testAnUnknownLanguageFallsBackToEnglish(): void
    {
        self::assertSame('1.0 KB', $this->extension('pl')->fileSize(1024));
    }

    /** `fr_BE` is French: the region says nothing about how a byte is named. */
    public function testTheRegionIsIgnored(): void
    {
        self::assertSame('1.0 Ko', $this->extension('fr_BE')->fileSize(1024));
    }

    /**
     * A document can carry no size at all - the column is nullable, and the
     * library holds rows that predate it. The template asks for the size
     * before it asks whether there is one, so this has to be an empty string
     * and not a crash or a "0 o" that claims the file is empty.
     */
    public function testAnAbsentSizeSaysNothing(): void
    {
        self::assertSame('', $this->extension('fr')->fileSize(null));
    }

    private function extension(string $locale): FileSizeExtension
    {
        $translator = $this->createStub(LocaleAwareInterface::class);
        $translator->method('getLocale')->willReturn($locale);

        return new FileSizeExtension($translator);
    }
}
