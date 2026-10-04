<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Environment;

/**
 * The zones and zone options added together: each renders, from settings an
 * author can type, to markup a reader can use.
 *
 * Rendered through the real template rather than asserted on the view: a
 * view that is right and a template that reads the wrong key is a page that
 * draws nothing, and nothing else would notice.
 */
final class GridWaveOneZonesTest extends IntegrationTestCase
{
    public function testAnAvailabilityZoneSaysItsSentence(): void
    {
        $html = $this->render(['type' => 'availability', 'options' => ['availability' => 'soon', 'availableFrom' => '2026-11-02']]);

        self::assertStringContainsString('Disponible à partir du 2 novembre 2026', $html);
    }

    public function testOpeningHoursPrintTheWeekInTheLanguageOfThePage(): void
    {
        $html = $this->render(['type' => 'openingHours', 'options' => ['hours' => ['mon' => [['09:00', '12:00']]]]]);

        self::assertStringContainsString('Lundi', $html);
        self::assertStringContainsString('9h00 – 12h00', $html);
        self::assertStringContainsString('Dimanche', $html);
    }

    public function testACountdownCarriesItsInstantForTheScript(): void
    {
        $html = $this->render(['type' => 'countdown', 'options' => ['countdownAt' => '2099-01-01T00:00']], ['label' => 'Lancement']);

        self::assertStringContainsString('data-countdown-at="2099-01-01T00:00:00+01:00"', $html);
        self::assertStringContainsString('Lancement', $html);
    }

    public function testABusinessCardLinksItsDetails(): void
    {
        $html = $this->render(
            ['type' => 'contactCard', 'options' => ['contactName' => 'Axel Raboit', 'contactPhone' => '06 12 34 56 78', 'contactEmail' => 'axel@example.com']],
            ['caption' => 'Développeur'],
        );

        self::assertStringContainsString('href="tel:0612345678"', $html);
        self::assertStringContainsString('href="mailto:axel@example.com"', $html);
        self::assertStringContainsString('data-vcard-file="axel-raboit.vcf"', $html);
    }

    public function testASocialPostShowsItsAccountAndText(): void
    {
        $html = $this->render(
            ['type' => 'socialPost', 'options' => ['socialName' => 'Axel Raboit', 'socialHandle' => 'axelraboit', 'socialNetwork' => 'linkedin']],
            ['caption' => "Première ligne\nDeuxième ligne"],
        );

        self::assertStringContainsString('@axelraboit · LinkedIn', $html);
        self::assertStringContainsString("Première ligne\nDeuxième ligne", $html);
    }

    public function testATerminalMarksItsCommands(): void
    {
        $html = $this->render(['type' => 'code', 'options' => ['codeStyle' => 'terminal']], ['code' => "$ make ft\nAll green"]);

        self::assertStringContainsString('data-terminal-command', $html);
        self::assertStringContainsString('make ft', $html);
        self::assertStringContainsString('All green', $html);
    }

    public function testADiffColoursItsLinesAndKeepsTheirMarks(): void
    {
        $html = $this->render(['type' => 'code', 'options' => ['codeStyle' => 'diff']], ['code' => "-const THRESHOLD = 0.08;\n+const THRESHOLD = 0;"]);

        self::assertStringContainsString('bg-rose-500/15', $html);
        self::assertStringContainsString('+const THRESHOLD = 0;', $html);
    }

    public function testAQrCodeCarriesItsAddressAndFallsBackToALink(): void
    {
        $html = $this->render(['type' => 'qrCode', 'size' => 'lg'], ['url' => 'https://axelraboit.fr', 'label' => 'Mon site']);

        self::assertStringContainsString('data-qr-text="https://axelraboit.fr"', $html);
        self::assertStringContainsString('href="https://axelraboit.fr"', $html);
        self::assertStringContainsString('data-qr-download="qr-code.png"', $html);
        self::assertStringContainsString('Mon site', $html);
    }

    /** Without an address there is nothing to encode, so nothing is drawn. */
    public function testAQrCodeWithoutAnAddressDrawsNothing(): void
    {
        self::assertSame('', mb_trim(strip_tags($this->render(['type' => 'qrCode']))));
    }

    public function testEveryChartShapeRenders(): void
    {
        foreach (['bar', 'line', 'donut', 'growth'] as $type) {
            $html = $this->render(['type' => 'chart', 'options' => ['chartType' => $type]], ['label' => 'Abonnés', 'code' => "Janvier ; 1200\nMars ; 2100"]);

            self::assertStringContainsString('Abonnés', $html, $type);
            self::assertStringContainsString('2', $html, $type);
        }
    }

    public function testACalendarPrintsItsMonthAndEntries(): void
    {
        $html = $this->render(['type' => 'editorialCalendar', 'options' => ['calendarMonth' => '2026-10']], ['code' => '2026-10-03 | Instagram | Réel coulisses']);

        self::assertStringContainsString('Octobre 2026', $html);
        self::assertStringContainsString('Réel coulisses', $html);
    }

    public function testAPriceListPrintsItsPrices(): void
    {
        $html = $this->render(['type' => 'priceList'], ['code' => "# Entrées\nSoupe du jour | 8 € | végétarien"]);

        self::assertStringContainsString('Entrées', $html);
        self::assertStringContainsString('8 €', $html);
        self::assertStringContainsString('végétarien', $html);
    }

    public function testAPollOffersItsAnswers(): void
    {
        $html = $this->render(['type' => 'poll'], ['label' => 'Quel format ?', 'code' => "Réels\nCarrousels"]);

        self::assertStringContainsString('Quel format ?', $html);
        self::assertStringContainsString('data-poll-answer="1"', $html);
    }

    public function testATravelMapPairsStopsWithTheirPhotos(): void
    {
        $html = $this->render(['type' => 'travelMap'], ['code' => "Monument Valley | 36.9989 | -110.098\nLondres | 51.5072 | -0.1276"]);

        self::assertStringContainsString('data-travel-map', $html);
        self::assertStringContainsString('Monument Valley', $html);
        self::assertStringContainsString('36.9989', $html);
    }

    /**
     * Photos pair with stops, not raw lines: a blank line between two stops,
     * as a translation easily picks up, used to hand the second stop the
     * photo meant for a third.
     */
    public function testABlankLineDoesNotShiftTheTravelPhotos(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $ids = [];
        foreach (['monument', 'londres'] as $name) {
            $document = new Document();
            $document->setTitle($name)->setOriginalName($name.'.jpg')->setMimeType('image/jpeg')
                ->setFilePath('ged/2026/09/'.$name.'-'.bin2hex(random_bytes(4)).'.jpg')
                ->setStatus(DocumentStatusEnum::Published);
            $entityManager->persist($document);
            $entityManager->flush();
            $ids[$name] = (int) $document->getId();
        }

        $grid = self::getContainer()->get(GridViewBuilder::class)->build(
            ['enabled' => true, 'zones' => [['id' => 'z1', 'type' => 'travelMap', 'mediaIds' => [$ids['monument'], $ids['londres']]]]],
            ['zones' => ['z1' => ['code' => "Monument Valley | 36.9989 | -110.098\n\nLondres | 51.5072 | -0.1276"]]],
            'fr',
        );
        $stops = $grid['zones'][0]['travelMap']['stops'] ?? [];

        self::assertCount(2, $stops);
        self::assertStringContainsString('londres', (string) ($stops[1]['photo']['url'] ?? ''), 'London keeps the second photo');
    }

    public function testATravelMapDropsAnUnreadableLine(): void
    {
        $html = $this->render(['type' => 'travelMap'], ['code' => "Pas assez de colonnes\nLondres | 51.5072 | -0.1276"]);

        self::assertStringContainsString('Londres', $html);
        self::assertStringNotContainsString('Pas assez', $html);
    }

    public function testAQuoteEstimatorSumsItsBaseAndItsOptions(): void
    {
        $html = $this->render(['type' => 'quoteEstimator'], ['label' => 'Estimez', 'code' => "= 90\nDrone | 40\nAlbum | 60"]);

        self::assertStringContainsString('Estimez', $html);
        self::assertStringContainsString('Drone', $html);
        self::assertStringContainsString('data-quote-base="90"', $html);
    }

    public function testAScrollyDisplayReadsAsAStoryOfChapters(): void
    {
        $html = $this->render(
            ['type' => 'items', 'display' => 'scrolly', 'items' => [['id' => 'c1']]],
            ['items' => ['c1' => ['title' => 'Le départ', 'description' => 'Premiers kilomètres.']]],
        );

        self::assertStringContainsString('Le départ', $html);
        self::assertStringContainsString('Premiers kilomètres.', $html);
        self::assertStringContainsString('lg:sticky', $html);
    }

    public function testAnEditorialListNumbersItsEntriesOnTwoDigits(): void
    {
        $html = $this->render(
            ['type' => 'items', 'display' => 'editorial', 'columns' => 2, 'items' => [['id' => 'e1'], ['id' => 'e2']]],
            ['items' => [
                'e1' => ['title' => 'Site vitrine', 'description' => 'Quelques pages, rapides.'],
                'e2' => ['title' => 'Application', 'description' => 'Un outil métier.'],
            ]],
        );

        self::assertStringContainsString('Site vitrine', $html);
        self::assertStringContainsString('Un outil métier.', $html);
        self::assertStringContainsString('>01</span>', $html);
        self::assertStringContainsString('>02</span>', $html);
        self::assertStringContainsString('sm:grid-cols-2', $html);
        self::assertStringNotContainsString('aurora-card', $html);
    }

    public function testAProcessLaysItsStopsOnOneLineAndFillsTheLast(): void
    {
        $html = $this->render(
            ['type' => 'items', 'display' => 'process', 'items' => [['id' => 'p1'], ['id' => 'p2'], ['id' => 'p3'], ['id' => 'p4']]],
            ['items' => [
                'p1' => ['title' => 'On se parle', 'description' => 'Un appel.', 'caption' => 'Jour 1'],
                'p2' => ['title' => 'On cadre'],
                'p3' => ['title' => 'On produit'],
                'p4' => ['title' => 'On publie'],
            ]],
        );

        self::assertStringContainsString('lg:grid-cols-4', $html);
        self::assertStringContainsString('On se parle', $html);
        self::assertStringContainsString('Un appel.', $html);
        // The number is the marker; the caption says when the stop happens,
        // above its title, and only on the stops that have one.
        self::assertStringContainsString('Jour 1', $html);
        self::assertLessThan(mb_strpos($html, 'On se parle'), (int) mb_strpos($html, 'Jour 1'));
        // Three links between four stops, and only the arrival is filled.
        self::assertSame(3, mb_substr_count($html, 'lg:h-px'));
        self::assertSame(1, mb_substr_count($html, 'bg-accent text-accent-text'));
    }

    public function testAProcessPastSixStopsWrapsFourToARow(): void
    {
        $entries = [];
        $held = [];
        foreach (range(1, 8) as $n) {
            $entries[] = ['id' => 's'.$n];
            $held['s'.$n] = ['title' => 'Temps '.$n];
        }

        $html = $this->render(['type' => 'items', 'display' => 'process', 'items' => $entries], ['items' => $held]);

        self::assertStringContainsString('lg:grid-cols-4', $html);
        // Seven gaps, minus the one at the end of the first row.
        self::assertSame(6, mb_substr_count($html, 'lg:h-px'));
    }

    public function testAPictureCanBecomeABandOrSitOnAScreen(): void
    {
        $band = $this->render(['type' => 'media', 'fullBleed' => true, 'mediaUrl' => 'https://picsum.photos/1600/900', 'options' => ['parallax' => true]], ['caption' => 'Une phrase']);
        self::assertStringContainsString('data-parallax', $band);
        self::assertStringContainsString('Une phrase', $band);

        $laptop = $this->render(['type' => 'media', 'mediaUrl' => 'https://picsum.photos/1600/1000', 'options' => ['frame' => 'laptop']]);
        self::assertStringContainsString('aspect-ratio: 16 / 10;', $laptop);
    }

    /** A card centred against the tall phone beside it, from the tablet up. */
    public function testAZoneCanSitCentredInATallerRow(): void
    {
        $text = ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Carte']]]];

        self::assertStringContainsString('md:self-center', $this->renderGrid(['type' => 'text', 'options' => ['valign' => 'center']], $text));
        self::assertStringNotContainsString('self-center', $this->renderGrid(['type' => 'text'], $text));
    }

    /** A zone can stay off the phone, or off the larger screens. */
    public function testAZoneCanStayOffAScreen(): void
    {
        $text = ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Décor']]]];

        self::assertStringContainsString('max-md:hidden', $this->renderGrid(['type' => 'text', 'options' => ['hideOn' => 'phone']], $text));
        self::assertStringContainsString('md:hidden', $this->renderGrid(['type' => 'text', 'options' => ['hideOn' => 'desktop']], $text));
        self::assertStringNotContainsString('hidden', $this->renderGrid(['type' => 'text'], $text));
    }

    /** The air between rows is the page's choice, the usual gap by default. */
    public function testThePageChoosesTheAirBetweenItsRows(): void
    {
        $text = ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Ligne']]]];

        self::assertStringContainsString('gap-y-8', $this->renderGrid(['type' => 'text'], $text));
        self::assertStringContainsString('gap-y-16', $this->renderGrid(['type' => 'text'], $text, ['rowGap' => 'loose']));
    }

    /**
     * @param array<string, mixed> $zone
     * @param array<string, mixed> $held
     * @param array<string, mixed> $page
     */
    private function renderGrid(array $zone, array $held, array $page = []): string
    {
        self::bootKernel();
        $builder = self::getContainer()->get(GridViewBuilder::class);
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        $grid = $builder->build(['enabled' => true, ...$page, 'zones' => [['id' => 'z1', ...$zone]]], ['zones' => ['z1' => $held]], 'fr');
        self::assertNotNull($grid);

        return $twig->render('Frontend/themes/default/editorial/post/_grid.html.twig', ['grid' => $grid, 'locale' => 'fr']);
    }

    /** A phone laid askew on the page, but upright on a phone itself. */
    public function testAPictureCanBeSetAskewFromTheTabletUp(): void
    {
        $tilted = $this->render(['type' => 'media', 'mediaUrl' => 'https://picsum.photos/900/1600', 'options' => ['frame' => 'phone', 'tilt' => 'right']]);
        self::assertStringContainsString('sm:rotate-3', $tilted);
        self::assertStringNotContainsString(' rotate-3', $tilted);

        $upright = $this->render(['type' => 'media', 'mediaUrl' => 'https://picsum.photos/900/1600', 'options' => ['frame' => 'phone']]);
        self::assertStringNotContainsString('rotate-3', $upright);
    }

    /** @param array<string, mixed> $zone @param array<string, mixed> $held */
    private function render(array $zone, array $held = []): string
    {
        self::bootKernel();
        $builder = self::getContainer()->get(GridViewBuilder::class);
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        $grid = $builder->build(
            ['enabled' => true, 'zones' => [['id' => 'z1', ...$zone]]],
            ['zones' => ['z1' => $held]],
            'fr',
        );

        self::assertNotNull($grid);

        return $twig->render('Frontend/themes/default/editorial/post/_grid_zone.html.twig', ['zone' => $grid['zones'][0], 'locale' => 'fr']);
    }
}
