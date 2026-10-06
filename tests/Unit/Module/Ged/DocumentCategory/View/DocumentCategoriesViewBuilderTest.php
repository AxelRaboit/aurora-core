<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Ged\DocumentCategory\View;

use Aurora\Core\Validation\Dto\PaginationRequest;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategory;
use Aurora\Module\Ged\DocumentCategory\Repository\DocumentCategoryRepository;
use Aurora\Module\Ged\DocumentCategory\Serializer\DocumentCategorySerializerInterface;
use Aurora\Module\Ged\DocumentCategory\View\DocumentCategoriesViewBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class DocumentCategoriesViewBuilderTest extends TestCase
{
    public function testIndexViewReturnsCategoriesAndPaths(): void
    {
        $repository = $this->createStub(DocumentCategoryRepository::class);
        $repository->method('findPaginated')->willReturn([
            'items' => [new DocumentCategory()],
            'total' => 1,
            'page' => 1,
            'totalPages' => 1,
        ]);

        $serializer = $this->createStub(DocumentCategorySerializerInterface::class);
        $serializer->method('serialize')->willReturn(['id' => 1]);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnArgument(0);

        $pagination = new PaginationRequest(1, 20, 'legal');
        $view = (new DocumentCategoriesViewBuilder($repository, $serializer, $urlGenerator))->indexView($pagination);

        self::assertArrayHasKey('categories', $view);
        self::assertSame('legal', $view['search']);
        self::assertArrayHasKey('createPath', $view);
    }

    public function testBuildListPayloadReturnsPaginated(): void
    {
        $repository = $this->createStub(DocumentCategoryRepository::class);
        $repository->method('findPaginated')->willReturn([
            'items' => [new DocumentCategory(), new DocumentCategory()],
            'total' => 2,
            'page' => 1,
            'totalPages' => 1,
        ]);

        $serializer = $this->createStub(DocumentCategorySerializerInterface::class);
        $serializer->method('serialize')->willReturn(['id' => 1]);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);

        $payload = (new DocumentCategoriesViewBuilder($repository, $serializer, $urlGenerator))
            ->buildListPayload(new PaginationRequest(1, 20, null));

        self::assertTrue($payload['success']);
        self::assertCount(2, $payload['items']);
        self::assertSame(2, $payload['total']);
    }
}
