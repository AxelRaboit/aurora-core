<?php

declare(strict_types=1);

namespace Aurora\Core\Testing\Concern;

use Aurora\Core\Storage\Service\UploadUrlGenerator;
use Aurora\Module\Ged\Document\Service\DocumentUrlGenerator;
use Aurora\Module\Platform\User\Service\UserProfilePhotoUrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Test helper: builds a `MediaUrlGenerator` / `UploadUrlGenerator` /
 * `UserProfilePhotoUrlGenerator` whose generated URLs mirror the
 * canonical `/uploads/<path>` shape. Keeps URL assertions in serializer
 * tests focused on serialization logic rather than route plumbing.
 *
 * All helpers stub `UrlGeneratorInterface::generate()` to return
 * `/uploads/<path>` regardless of the route name (`uploads_serve` in
 * prod), so any test asserting on a URL just keeps working.
 *
 * Lives under `src/Core/Testing/` (rather than `tests/`) so client
 * projects consuming aurora-core via composer can `use` the trait too
 * - keeps the helper single-sourced across the ecosystem.
 *
 * @api Test-only utility consumed exclusively from `tests/` directories
 *      across the ecosystem. PHPStan only scans `src/`, so without this
 *      marker the trait would be flagged unused.
 */
trait CreatesStorageUrlGenerators
{
    private function makeUploadUrlGenerator(): UploadUrlGenerator
    {
        return new UploadUrlGenerator($this->makeStubbedUrlGenerator());
    }

    private function makeDocumentUrlGenerator(): DocumentUrlGenerator
    {
        return new DocumentUrlGenerator($this->makeStubbedUrlGenerator());
    }

    private function makeUserProfilePhotoUrlGenerator(): UserProfilePhotoUrlGenerator
    {
        return new UserProfilePhotoUrlGenerator($this->makeStubbedUrlGenerator());
    }

    private function makeStubbedUrlGenerator(): UrlGeneratorInterface
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $name, array $params = []): string => '/uploads/'.($params['path'] ?? ''),
        );

        return $urlGenerator;
    }
}
