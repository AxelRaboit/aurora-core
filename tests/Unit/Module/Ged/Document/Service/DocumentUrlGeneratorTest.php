<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Ged\Document\Service;

use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Service\DocumentUrlGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AllowMockObjectsWithoutExpectations]
final class DocumentUrlGeneratorTest extends TestCase
{
    private UrlGeneratorInterface $urlGenerator;
    private DocumentUrlGenerator $documentUrlGenerator;

    protected function setUp(): void
    {
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->documentUrlGenerator = new DocumentUrlGenerator($this->urlGenerator);
    }

    /** @param array<string, string> $variants */
    private function makeDocument(?string $filePath, array $variants = [], ?float $focalX = null, ?float $focalY = null): DocumentInterface
    {
        $document = $this->createMock(DocumentInterface::class);
        $document->method('getFilePath')->willReturn($filePath);
        $document->method('getVariants')->willReturn($variants);
        $document->method('getFocalX')->willReturn($focalX);
        $document->method('getFocalY')->willReturn($focalY);

        return $document;
    }

    public function testPublicUrlReturnsNullWhenDocumentIsNull(): void
    {
        self::assertNull($this->documentUrlGenerator->publicUrl(null));
    }

    public function testPublicUrlReturnsNullWhenFilePathIsNull(): void
    {
        $document = $this->makeDocument(null);
        $this->urlGenerator->expects(self::never())->method('generate');

        self::assertNull($this->documentUrlGenerator->publicUrl($document));
    }

    public function testPublicUrlGeneratesUploadsServeWithRelativePath(): void
    {
        $this->urlGenerator->expects(self::once())
            ->method('generate')
            ->with('uploads_serve', ['path' => 'ged/2026/05/contract.pdf'])
            ->willReturn('/uploads/ged/2026/05/contract.pdf');

        $url = $this->documentUrlGenerator->publicUrl($this->makeDocument('ged/2026/05/contract.pdf'));

        self::assertSame('/uploads/ged/2026/05/contract.pdf', $url);
    }

    public function testPublicUrlAbsoluteUsesAbsoluteReferenceType(): void
    {
        $this->urlGenerator->expects(self::once())
            ->method('generate')
            ->with(
                'uploads_serve',
                ['path' => 'ged/2026/05/contract.pdf'],
                UrlGeneratorInterface::ABSOLUTE_URL,
            )
            ->willReturn('https://aurora.test/uploads/ged/2026/05/contract.pdf');

        $url = $this->documentUrlGenerator->publicUrlAbsolute($this->makeDocument('ged/2026/05/contract.pdf'));

        self::assertSame('https://aurora.test/uploads/ged/2026/05/contract.pdf', $url);
    }

    public function testVariantUrlReturnsNullWhenDocumentIsNull(): void
    {
        self::assertNull($this->documentUrlGenerator->variantUrl(null, 'medium'));
    }

    public function testVariantUrlReturnsNullWhenVariantIsMissing(): void
    {
        $document = $this->makeDocument('ged/2026/05/photo.webp', ['thumbnail' => 'ged/.../photo.thumbnail.webp']);
        $this->urlGenerator->expects(self::never())->method('generate');

        self::assertNull($this->documentUrlGenerator->variantUrl($document, 'medium'));
    }

    public function testVariantUrlGeneratesWithTheVariantPath(): void
    {
        $document = $this->makeDocument('ged/2026/05/photo.webp', [
            'medium' => 'ged/2026/05/variants/medium/photo.webp',
        ]);
        $this->urlGenerator->expects(self::once())
            ->method('generate')
            ->with('uploads_serve', ['path' => 'ged/2026/05/variants/medium/photo.webp'])
            ->willReturn('/uploads/ged/2026/05/variants/medium/photo.webp');

        self::assertSame(
            '/uploads/ged/2026/05/variants/medium/photo.webp',
            $this->documentUrlGenerator->variantUrl($document, 'medium'),
        );
    }

    public function testThumbUrlPrefersMedium(): void
    {
        $document = $this->makeDocument('ged/2026/05/photo.webp', [
            'medium' => 'ged/2026/05/variants/medium/photo.webp',
            'large' => 'ged/2026/05/variants/large/photo.webp',
        ]);
        $this->urlGenerator->expects(self::once())
            ->method('generate')
            ->with('uploads_serve', ['path' => 'ged/2026/05/variants/medium/photo.webp'])
            ->willReturn('/uploads/ged/2026/05/variants/medium/photo.webp');

        self::assertSame(
            '/uploads/ged/2026/05/variants/medium/photo.webp',
            $this->documentUrlGenerator->thumbUrl($document),
        );
    }

    public function testThumbUrlFallsBackToLargeWhenMediumIsMissing(): void
    {
        $document = $this->makeDocument('ged/2026/05/photo.webp', [
            'large' => 'ged/2026/05/variants/large/photo.webp',
        ]);
        $this->urlGenerator->expects(self::once())
            ->method('generate')
            ->with('uploads_serve', ['path' => 'ged/2026/05/variants/large/photo.webp'])
            ->willReturn('/uploads/ged/2026/05/variants/large/photo.webp');

        self::assertSame(
            '/uploads/ged/2026/05/variants/large/photo.webp',
            $this->documentUrlGenerator->thumbUrl($document),
        );
    }

    public function testThumbUrlFallsBackToOriginalWhenNoVariantExists(): void
    {
        // Non-image documents (PDF, SVG) - no variant was produced, so the
        // thumb cascade must end on the original public path.
        $document = $this->makeDocument('ged/2026/05/contract.pdf');
        $this->urlGenerator->expects(self::once())
            ->method('generate')
            ->with('uploads_serve', ['path' => 'ged/2026/05/contract.pdf'])
            ->willReturn('/uploads/ged/2026/05/contract.pdf');

        self::assertSame(
            '/uploads/ged/2026/05/contract.pdf',
            $this->documentUrlGenerator->thumbUrl($document),
        );
    }

    public function testFocalPositionCssCentersByDefault(): void
    {
        self::assertSame('50% 50%', $this->documentUrlGenerator->focalPositionCss(null));
        self::assertSame('50% 50%', $this->documentUrlGenerator->focalPositionCss($this->makeDocument('ged/photo.webp')));
    }

    public function testFocalPositionCssScalesNormalizedCoordinatesToPercents(): void
    {
        $document = $this->makeDocument('ged/photo.webp', [], 0.25, 0.75);

        self::assertSame('25% 75%', $this->documentUrlGenerator->focalPositionCss($document));
    }
}
