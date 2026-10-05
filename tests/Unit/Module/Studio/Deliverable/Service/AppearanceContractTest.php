<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Studio\Deliverable\Service;

use Aurora\Module\Configuration\Theme\Service\AppearanceValues;
use Aurora\Module\Editorial\Post\Dto\PostInputFactory;
use Aurora\Module\Studio\Deliverable\Service\DeliverableAppearance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A publication and a deliverable read the colours chosen for ONE page the same
 * way.
 *
 * The publications keep theirs in columns, the deliverables in a JSON with its
 * own normalizer, and both end in a public `<style>`. They share the reading of
 * a colour and of a hover mode ({@see AppearanceValues}); this is the contract
 * that says so, so that fixing one cannot leave the other lax.
 */
final class AppearanceContractTest extends TestCase
{
    private const array SHARED = ['headerColor', 'footerColor', 'backgroundColor', 'accentColor', 'highlight', 'highlightColor'];

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function inputs(): iterable
    {
        yield 'valid colours' => [['headerColor' => '#130918', 'footerColor' => '#AABBCC', 'backgroundColor' => '#ffffff', 'accentColor' => '#bd4a55', 'highlight' => 'accent']];
        yield 'edge spaces' => [['accentColor' => "  #bd4a55\n", 'backgroundColor' => ' #ffffff ']];
        yield 'a short hex is refused' => [['accentColor' => '#fff', 'headerColor' => '#12345']];
        yield 'css injection' => [['accentColor' => 'red;} body{display:none', 'backgroundColor' => '#ffffff;background:url(x)', 'footerColor' => 'url(javascript:alert(1))']];
        yield 'not text' => [['accentColor' => ['#bd4a55'], 'headerColor' => 12, 'footerColor' => true]];
        yield 'custom hover with a colour' => [['highlight' => 'custom', 'highlightColor' => '#8b6cff']];
        yield 'custom hover without a colour' => [['highlight' => 'custom']];
        yield 'custom hover with a bad colour' => [['highlight' => 'custom', 'highlightColor' => 'purple']];
        yield 'unknown hover' => [['highlight' => 'glow', 'highlightColor' => '#8b6cff']];
        yield 'neutral hover' => [['highlight' => 'neutral']];
        yield 'nothing' => [[]];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('inputs')]
    public function testTheSharedFieldsAreReadTheSameWay(array $input): void
    {
        $deliverable = DeliverableAppearance::normalize($input);
        $post = new PostInputFactory()->fromArray($input);

        foreach (self::SHARED as $field) {
            self::assertSame($post->{'get'.ucfirst($field)}(), $deliverable[$field], $field);
        }
    }

    public function testAPageColourIsExactlySixHexDigits(): void
    {
        self::assertSame('#130918', AppearanceValues::color('#130918'));
        self::assertSame('#AABBCC', AppearanceValues::color(' #AABBCC '));
        self::assertNull(AppearanceValues::color('#fff'));
        self::assertNull(AppearanceValues::color('#1309180'));
        self::assertNull(AppearanceValues::color('130918'));
        self::assertNull(AppearanceValues::color(null));
        self::assertNull(AppearanceValues::color(['#130918']));
    }

    public function testACustomHoverNeedsAColour(): void
    {
        self::assertSame('custom', AppearanceValues::highlight('custom', '#8b6cff'));
        self::assertNull(AppearanceValues::highlight('custom', null));
        self::assertNull(AppearanceValues::highlight('custom', 'purple'));
        self::assertSame('neutral', AppearanceValues::highlight('neutral', null));
        self::assertNull(AppearanceValues::highlight('glow', '#8b6cff'));
        self::assertNull(AppearanceValues::highlight(['accent'], null));
    }
}
