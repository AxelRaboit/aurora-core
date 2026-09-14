<?php

declare(strict_types=1);

namespace Aurora\Core\Twig;

use Symfony\Contracts\Translation\LocaleAwareInterface;
use Twig\Attribute\AsTwigFilter;

/**
 * A number of bytes, spelled the way the reader's language spells it.
 *
 * The **mirror of `useFileSize.js`**, which does the same job for the backend's
 * Vue lists: the same thresholds, the same number of decimals at each step, and
 * the same unit table. They are two implementations of one convention, and a
 * reader who sees "1.0 Mo" in the library and "1.05 MB" on the page they
 * published is being shown two different products. `FileSizeExtensionTest`
 * holds this side to the values the composable's own test asserts.
 *
 * Powers of 1024 rather than of 1000, which is what every file manager these
 * readers use does, whatever the standards body says.
 *
 * The locale comes from the translator rather than from an argument: the
 * frontend already runs a request in one language, and asking a template to
 * pass it would be asking it to remember something the container knows.
 */
final readonly class FileSizeExtension
{
    /**
     * The unit names per language, and the fallback the rest of the world
     * gets. French is the only one of the four that translates the byte.
     *
     * @var array<string, array{b: string, kb: string, mb: string, gb: string}>
     */
    private const array UNITS = [
        'fr' => ['b' => 'o', 'kb' => 'Ko', 'mb' => 'Mo', 'gb' => 'Go'],
        'en' => ['b' => 'B', 'kb' => 'KB', 'mb' => 'MB', 'gb' => 'GB'],
        'es' => ['b' => 'B', 'kb' => 'KB', 'mb' => 'MB', 'gb' => 'GB'],
        'de' => ['b' => 'B', 'kb' => 'KB', 'mb' => 'MB', 'gb' => 'GB'],
    ];

    public function __construct(
        private LocaleAwareInterface $translator,
    ) {}

    #[AsTwigFilter(name: 'file_size')]
    public function fileSize(?int $bytes): string
    {
        if (null === $bytes || $bytes < 0) {
            return '';
        }

        $units = $this->unitsFor($this->translator->getLocale());

        if ($bytes < 1024) {
            return sprintf('%d %s', $bytes, $units['b']);
        }

        if ($bytes < 1024 ** 2) {
            return sprintf('%.1f %s', $bytes / 1024, $units['kb']);
        }

        if ($bytes < 1024 ** 3) {
            return sprintf('%.1f %s', $bytes / 1024 ** 2, $units['mb']);
        }

        return sprintf('%.2f %s', $bytes / 1024 ** 3, $units['gb']);
    }

    /**
     * @return array{b: string, kb: string, mb: string, gb: string}
     */
    private function unitsFor(string $locale): array
    {
        // `fr_BE` is French. Splitting on the region rather than looking the
        // whole thing up is what the composable does too, and for the same
        // reason: a units table per region would be the same four rows copied.
        $language = mb_strtolower(explode('-', str_replace('_', '-', $locale))[0]);

        return self::UNITS[$language] ?? self::UNITS['en'];
    }
}
