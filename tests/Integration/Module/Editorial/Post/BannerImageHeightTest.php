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
            ->setVariants(['large' => "ged/2026/09/variants/large/{$name}.webp"]);
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        return (int) $document->getId();
    }
}
