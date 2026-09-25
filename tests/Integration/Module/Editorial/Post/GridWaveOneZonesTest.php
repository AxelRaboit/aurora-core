<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Aurora\Tests\Integration\IntegrationTestCase;
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

    public function testAPictureCanBecomeABandOrSitOnAScreen(): void
    {
        $band = $this->render(['type' => 'media', 'fullBleed' => true, 'mediaUrl' => 'https://picsum.photos/1600/900', 'options' => ['parallax' => true]], ['caption' => 'Une phrase']);
        self::assertStringContainsString('data-parallax', $band);
        self::assertStringContainsString('Une phrase', $band);

        $laptop = $this->render(['type' => 'media', 'mediaUrl' => 'https://picsum.photos/1600/1000', 'options' => ['frame' => 'laptop']]);
        self::assertStringContainsString('aspect-ratio: 16 / 10;', $laptop);
    }

    /** @param array<string, mixed> $zone @param array<string, mixed> $held */
    private function render(array $zone, array $held = []): string
    {
        static::bootKernel();
        $builder = static::getContainer()->get(GridViewBuilder::class);
        $twig = static::getContainer()->get(Environment::class);
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
