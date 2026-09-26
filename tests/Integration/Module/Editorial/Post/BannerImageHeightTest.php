<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Banner\BannerViewBuilder;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Environment;

/**
 * A banner whose picture carries words cannot be cropped: a cover crop cuts
 * the top and bottom on a wide screen and the sides on a narrow phone. The
 * `image` height gives the header its picture's proportions instead.
 */
final class BannerImageHeightTest extends IntegrationTestCase
{
    private const string TEMPLATE = 'Frontend/themes/default/editorial/post/_banner.html.twig';

    private BannerViewBuilder $bannerViewBuilder;

    private EntityManagerInterface $entityManager;

    private Environment $twig;

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();

        $this->bannerViewBuilder = static::getContainer()->get(BannerViewBuilder::class);
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->twig = static::getContainer()->get(Environment::class);
    }

    public function testTheHeaderTakesItsPicturesProportions(): void
    {
        $wide = $this->picture(3840, 1364);
        $tall = $this->picture(2160, 2960);

        $html = $this->render(['mediaId' => $wide, 'mobileMediaId' => $tall]);

        self::assertStringContainsString('--banner-ratio: 3840 / 1364;', $html);
        self::assertStringContainsString('[aspect-ratio:var(--banner-ratio)]', $html);
        self::assertStringContainsString('--banner-mobile-ratio: 2160 / 2960;', $html);
        self::assertStringContainsString('max-sm:[aspect-ratio:var(--banner-mobile-ratio)]', $html);
        self::assertStringNotContainsString('min-h-[32rem]', $html);
    }

    /**
     * Between `sm` and `lg` a tablet picture brings its own file, proportions
     * and focal point, and stays out of the phone's and the wide screen's way.
     */
    public function testATabletPictureTakesOverBetweenThePhoneAndTheWideScreen(): void
    {
        $html = $this->render(['mediaId' => $this->picture(3840, 1364), 'tabletMediaId' => $this->picture(2048, 1240)]);

        self::assertStringContainsString('--banner-tablet-ratio: 2048 / 1240;', $html);
        self::assertStringContainsString('sm:max-lg:[aspect-ratio:var(--banner-tablet-ratio)]', $html);
        self::assertStringContainsString('media="(min-width: 640px) and (max-width: 1023px)"', $html);
        self::assertStringContainsString('--banner-tablet-focal:', $html);
        self::assertStringNotContainsString('--banner-mobile-ratio', $html);
    }

    /**
     * Every focal point travels as a custom property read by a class. An
     * inline `object-position` for the wide picture outranked the phone and
     * tablet classes, and their pictures were always framed on the centre.
     */
    public function testNoFocalPointIsWrittenInline(): void
    {
        $html = $this->render([
            'mediaId' => $this->picture(3840, 1364),
            'mobileMediaId' => $this->picture(2160, 2960),
            'tabletMediaId' => $this->picture(2048, 1240),
        ]);

        self::assertStringNotContainsString('style="object-position', $html);
        self::assertStringContainsString('[object-position:var(--banner-focal)]', $html);
        self::assertStringContainsString('--banner-focal:', $html);
        self::assertStringContainsString('max-sm:[object-position:var(--banner-mobile-focal)]', $html);
        self::assertStringContainsString('sm:max-lg:[object-position:var(--banner-tablet-focal)]', $html);
    }

    /** From 640px a banner lays out in columns: its grid carries the class that says so. */
    public function testTheBannerGridTakesItsTabletColumnsFromSm(): void
    {
        self::assertStringContainsString('aurora-grid-banner', $this->render(['mediaId' => $this->picture(3840, 1364)]));
    }

    public function testWithoutATabletPictureTheTabletKeepsTheWideOne(): void
    {
        $html = $this->render(['mediaId' => $this->picture(3840, 1364)]);

        self::assertStringNotContainsString('--banner-tablet', $html);
        self::assertStringNotContainsString('(min-width: 640px) and (max-width: 1023px)', $html);
    }

    public function testWithoutAPhonePictureThePhoneKeepsTheWideRatio(): void
    {
        $html = $this->render(['mediaId' => $this->picture(1920, 682)]);

        self::assertStringContainsString('--banner-ratio: 1920 / 682;', $html);
        self::assertStringNotContainsString('--banner-mobile-ratio', $html);
    }

    /**
     * A file with no recorded size has no proportions to follow: the banner
     * keeps the tallest ordinary height rather than collapsing to nothing.
     */
    public function testAPictureOfUnknownSizeFallsBackToTheTallHeight(): void
    {
        $html = $this->render(['mediaId' => $this->picture(null, null)]);

        self::assertStringContainsString('min-h-[32rem]', $html);
        self::assertStringNotContainsString('--banner-ratio', $html);
    }

    /** @param array<string, mixed> $background */
    private function render(array $background): string
    {
        $banner = $this->bannerViewBuilder->build(
            ['enabled' => true, 'height' => 'image', 'width' => 'full_aligned', 'background' => $background],
            [],
        );
        self::assertNotNull($banner);

        return $this->twig->render(self::TEMPLATE, ['banner' => $banner]);
    }

    private function picture(?int $width, ?int $height): int
    {
        $name = bin2hex(random_bytes(4));
        $document = new Document();
        $document->setTitle('Entête')->setMimeType('image/png')->setFilePath("ged/2026/09/{$name}.png")
            ->setWidth($width)->setHeight($height)
            ->setRenditions(['large' => "ged/2026/09/variants/large/{$name}.webp"]);
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        return (int) $document->getId();
    }
}
