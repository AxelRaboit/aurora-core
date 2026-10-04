<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Twig;

use Aurora\Core\Twig\PlaceholderMarkExtension;
use PHPUnit\Framework\TestCase;

final class PlaceholderMarkExtensionTest extends TestCase
{
    public function testItLightsTheBlanksInTheText(): void
    {
        self::assertSame(
            '<p><mark class="aurora-placeholder">[Nom de la marque]</mark> · <mark class="aurora-placeholder">[Mois année]</mark></p>',
            new PlaceholderMarkExtension()->mark('<p>[Nom de la marque] · [Mois année]</p>'),
        );
    }

    /** Attributes, scripts and styles carry brackets that are code, not blanks. */
    public function testItLeavesTagsScriptsAndStylesAlone(): void
    {
        $html = '<a title="[x]" data-v="[1]">lien</a><script>const a = [1];</script><style>.x[data-y] {}</style>';

        self::assertSame($html, new PlaceholderMarkExtension()->mark($html));
    }

    public function testAnEmptyPairOrAPairAcrossLinesIsNotABlank(): void
    {
        self::assertSame("<p>[] et [deux\nlignes]</p>", new PlaceholderMarkExtension()->mark("<p>[] et [deux\nlignes]</p>"));
    }
}
