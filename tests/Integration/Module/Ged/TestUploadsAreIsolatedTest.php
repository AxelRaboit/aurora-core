<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Ged;

use Aurora\Tests\Integration\IntegrationTestCase;

/**
 * The suite must not upload into the directory a developer's own install
 * serves from.
 *
 * It did, for months. Every run of the image upload contract left a
 * `pixel-*.png` and its variants behind in `var/uploads/ged/`, and nothing
 * removed them: 1184 files had accumulated, discovered only because the
 * orphan sweep reported them.
 */
final class TestUploadsAreIsolatedTest extends IntegrationTestCase
{
    public function testTheTestEnvironmentUploadsSomewhereDisposable(): void
    {
        static::createClient();
        $container = static::getContainer();

        $uploadDir = (string) $container->getParameter('app.upload_dir');
        $projectDir = (string) $container->getParameter('kernel.project_dir');

        self::assertNotSame(
            $projectDir.'/var/uploads',
            $uploadDir,
            'The test environment must override app.upload_dir; see config/packages/test/storage.yaml.',
        );
        self::assertStringStartsWith($projectDir.'/var/', $uploadDir);
    }
}
