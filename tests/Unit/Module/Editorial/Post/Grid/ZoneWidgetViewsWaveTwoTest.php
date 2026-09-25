<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Post\Grid;

use Aurora\Core\Content\ContentValueNormalizer;
use Aurora\Module\Editorial\Post\Grid\GridNormalizer;
use Aurora\Module\Editorial\Post\Grid\ZoneWidgetViews;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * The zones whose content is a small table typed in the editor.
 */
final class ZoneWidgetViewsWaveTwoTest extends TestCase
{
    public function testABarChartScalesItsBarsToTheLargestValue(): void
    {
        $view = $this->build('chart', ['chartUnit' => '%'], code: "Janvier ; 50\nFévrier ; 100\nMars ; 25,5\nligne sans nombre");

        self::assertCount(3, $view['rows']);
        self::assertSame(100.0, $view['rows'][1]['share']);
        self::assertSame(50.0, $view['rows'][0]['share']);
        self::assertSame("25,5\u{202f}%", $view['rows'][2]['valueLabel']);
    }

    public function testAGrowthCurveSaysHowFarItWent(): void
    {
        $view = $this->build('chart', ['chartType' => 'growth'], code: "Avant ; 1000\nAprès ; 2500");

        self::assertSame('+150 %', $view['growth']);
        self::assertTrue($view['growthUp']);
        self::assertCount(2, $view['points']);
    }

    public function testARingSharesOneHundred(): void
    {
        $view = $this->build('chart', ['chartType' => 'donut'], code: "Instagram ; 3\nLinkedIn ; 1");

        self::assertSame(75.0, $view['rows'][0]['dash']);
        self::assertSame(25.0, $view['rows'][1]['dash']);
    }

    public function testAChartWithNoValueDrawsNothing(): void
    {
        self::assertNull($this->build('chart', code: 'rien à compter'));
    }

    public function testACalendarLaysTheMonthOutMondayFirst(): void
    {
        $view = $this->build('editorialCalendar', code: "2026-10-03 | Instagram | Réel coulisses\n2026-10-07 | LinkedIn | Étude de cas\nhier | x | ignorée");

        // October 2026 starts on a Thursday: three days of September lead in.
        self::assertFalse($view['weeks'][0][0]['inMonth']);
        self::assertSame(1, $view['weeks'][0][3]['day']);
        self::assertSame('Réel coulisses', $view['weeks'][0][5]['entries'][0]['title']);
        self::assertSame('instagram', $view['weeks'][0][5]['entries'][0]['tone']);
        self::assertCount(2, $view['list']);
    }

    public function testAPriceListHasSectionsLinesAndTags(): void
    {
        $view = $this->build('priceList', code: "# Entrées\nSoupe du jour | 8 € | végétarien, nouveau | selon le marché\n# Plats\nTartare | 14 €");

        self::assertSame('Entrées', $view['sections'][0]['title']);
        self::assertSame(['végétarien', 'nouveau'], $view['sections'][0]['items'][0]['tags']);
        self::assertSame('selon le marché', $view['sections'][0]['items'][0]['detail']);
        self::assertSame('14 €', $view['sections'][1]['items'][0]['price']);
    }

    public function testChaptersAreTimesAndTheTranscriptIsBelowTheDashes(): void
    {
        $view = $this->build('audio', code: "00:00 Introduction\n04:12 Le matériel\n1:02:30 La fin\n---\nBonjour à tous.");

        self::assertSame([0, 252, 3750], array_column($view['chapters'], 'seconds'));
        self::assertSame('Bonjour à tous.', $view['transcript']);
    }

    public function testAPollNeedsAQuestionAndTwoAnswers(): void
    {
        self::assertNull($this->build('poll', label: 'Une seule ?', code: 'Oui'));

        $view = $this->build('poll', label: 'Quel format ?', code: "Réels\nCarrousels");
        self::assertSame(['Réels', 'Carrousels'], array_column($view['answers'], 'label'));
        self::assertSame(0, $view['total']);
        self::assertFalse($view['showResults']);
    }

    /** @param array<string, mixed> $options @return array<string, mixed>|null */
    private function build(string $type, array $options = [], string $code = '', string $label = ''): ?array
    {
        $zone = (new GridNormalizer(new ContentValueNormalizer()))->normalizeLayout([
            'zones' => [['id' => 'z1', 'type' => $type, 'options' => $options]],
        ])['zones'][0];

        $held = ['blocks' => [], 'alt' => '', 'caption' => '', 'url' => null, 'label' => $label, 'code' => $code, 'items' => []];

        return (new ZoneWidgetViews(new IdentityTranslator(), new MockClock(new DateTimeImmutable('2026-10-10 10:00:00 UTC'))))
            ->build($zone, $held, 'fr', static fn () => null);
    }
}
