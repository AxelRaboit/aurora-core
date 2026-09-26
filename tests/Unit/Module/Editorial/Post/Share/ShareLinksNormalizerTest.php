<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Post\Share;

use Aurora\Module\Editorial\Post\Share\ShareLinksNormalizer;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_fill;
use function count;
use function mb_strlen;
use function str_repeat;

final class ShareLinksNormalizerTest extends TestCase
{
    private ShareLinksNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new ShareLinksNormalizer();
    }

    /** Nothing sent is "never configured", which keeps the default row. */
    public function testAbsentLinksStayNull(): void
    {
        self::assertNull($this->normalizer->normalize(null));
        self::assertNull($this->normalizer->normalize('copy'));
    }

    /** An empty list is a choice: the page shows no link. */
    public function testAnEmptyListStaysEmpty(): void
    {
        self::assertSame([], $this->normalizer->normalize([]));
    }

    public function testALinkKeepsItsTypeLabelAndColourInOrder(): void
    {
        self::assertSame(
            [
                ['type' => 'whatsapp', 'label' => 'Envoyer', 'url' => null, 'color' => '#25d366'],
                ['type' => 'copy', 'label' => null, 'url' => null, 'color' => null],
            ],
            $this->normalizer->normalize([
                ['type' => 'whatsapp', 'label' => '  Envoyer ', 'color' => '#25d366', 'url' => 'https://ignored.example'],
                ['type' => 'copy', 'label' => '', 'color' => 'red'],
            ]),
        );
    }

    public function testAnUnknownTypeIsDropped(): void
    {
        self::assertSame([], $this->normalizer->normalize([['type' => 'myspace'], 'copy', ['label' => 'x']]));
    }

    /**
     * The address lands in the href of a public page: only https and mailto
     * get there, and a custom link without one is dropped.
     */
    public function testACustomAddressMustBeHttpsOrMailto(): void
    {
        $links = $this->normalizer->normalize([
            ['type' => 'custom', 'url' => 'javascript:alert(1)'],
            ['type' => 'custom', 'url' => 'http://plain.example'],
            ['type' => 'custom', 'url' => 'https://x.example/?u= {url}'],
            ['type' => 'custom', 'url' => ''],
            ['type' => 'custom', 'url' => 'https://share.example/?u={url}&t={title}'],
            ['type' => 'custom', 'url' => 'mailto:hello@example.com'],
            ['type' => 'custom', 'url' => 'https://'.str_repeat('a', 600).'.example'],
        ]);

        self::assertSame(
            ['https://share.example/?u={url}&t={title}', 'mailto:hello@example.com'],
            array_column((array) $links, 'url'),
        );
    }

    public function testALabelIsCapped(): void
    {
        $links = $this->normalizer->normalize([['type' => 'copy', 'label' => str_repeat('é', 100)]]);

        self::assertSame(60, mb_strlen((string) $links[0]['label']));
    }

    public function testTheListIsCapped(): void
    {
        $links = $this->normalizer->normalize(array_fill(0, 20, ['type' => 'copy']));

        self::assertSame(ShareLinksNormalizer::MAX_LINKS, count((array) $links));
    }
}
