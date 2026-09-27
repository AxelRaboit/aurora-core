<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Post\Share;

use Aurora\Module\Editorial\Post\Share\UsefulLinksNormalizer;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function dirname;
use function file_get_contents;
use function mb_strlen;
use function preg_match;
use function str_repeat;

final class UsefulLinksNormalizerTest extends TestCase
{
    public function testNothingSentIsNoLinks(): void
    {
        self::assertSame([], new UsefulLinksNormalizer()->normalize(null));
        self::assertSame([], new UsefulLinksNormalizer()->normalize('https://example.com'));
    }

    public function testAGoodLinkKeepsItsWordsAddressAndColour(): void
    {
        self::assertSame(
            [['label' => 'GitHub', 'url' => 'https://github.com/AxelRaboit', 'color' => '#34d399']],
            new UsefulLinksNormalizer()->normalize([['label' => '  GitHub ', 'url' => ' https://github.com/AxelRaboit ', 'color' => '#34d399']]),
        );
    }

    /** Every one of these would be a button to nowhere, or a script in a visitor's browser. */
    public function testALinkWithoutWordsOrWithAnUnsafeAddressIsDropped(): void
    {
        $links = new UsefulLinksNormalizer()->normalize([
            ['label' => '', 'url' => 'https://example.com'],
            ['label' => 'Sans adresse'],
            ['label' => 'Piège', 'url' => 'javascript:alert(1)'],
            ['label' => 'En clair', 'url' => 'http://example.com'],
            ['label' => 'Écrire', 'url' => 'mailto:hello@example.com', 'color' => 'red'],
        ]);

        self::assertSame([['label' => 'Écrire', 'url' => 'mailto:hello@example.com', 'color' => null]], $links);
    }

    public function testWordsAreCutAndTheListIsCapped(): void
    {
        $links = new UsefulLinksNormalizer()->normalize(array_fill(0, 20, ['label' => str_repeat('a', 80), 'url' => 'https://example.com']));

        self::assertCount(UsefulLinksNormalizer::MAX_LINKS, $links);
        self::assertSame(60, mb_strlen($links[0]['label']));
    }

    /** The editor greys its add button at the same count the server keeps. */
    public function testTheEditorCapsTheListWhereTheServerDoes(): void
    {
        $source = file_get_contents(dirname(__DIR__, 6).'/src/Module/Editorial/assets/backend/posts/components/UsefulLinksField.vue');
        self::assertIsString($source);
        self::assertSame(1, preg_match('/const MAX_USEFUL_LINKS = (\d+);/', $source, $matches), 'MAX_USEFUL_LINKS is not in UsefulLinksField.vue any more');

        self::assertSame(UsefulLinksNormalizer::MAX_LINKS, (int) $matches[1]);
    }
}
