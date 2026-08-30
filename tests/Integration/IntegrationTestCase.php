<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration;

use Aurora\Core\Bootstrap\BootstrapRunner;
use Aurora\Core\DataFixtures\AppFixtures;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

abstract class IntegrationTestCase extends WebTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        static::bootKernel();
        $container = static::getContainer();

        $entityManager = $container->get(EntityManagerInterface::class);

        // The suite uploads real files. They go to `var/test-uploads` (see
        // config/services_test.yaml) rather than the directory a developer's
        // own install serves from, and the directory starts empty so one run
        // never inherits the previous one's leftovers.
        //
        // The name is checked before the removal, and that is not belt and
        // braces: the first attempt at this put the override in
        // `config/packages/test/`, where `config/services.yaml` overwrites it,
        // so the very first run of this line erased the real `var/uploads`.
        $uploadDir = (string) $container->getParameter('app.upload_dir');
        self::assertStringEndsWith('/var/test-uploads', $uploadDir, 'Refusing to purge: the test environment is not using its own upload directory.');
        (new Filesystem())->remove($uploadDir);

        // Purge first, then seed the mandatory rows, then the dev accounts.
        // The bootstrap providers run here rather than being duplicated into a
        // fixture: a test database that is seeded differently from a real
        // install is a test database that can hide breakage - this suite went
        // red precisely because the locales and the theme moved, which is the
        // signal working as intended.
        $executor = new ORMExecutor($entityManager, new ORMPurger($entityManager));
        $executor->purge();

        // Every provider, through the same runner `aurora:install` uses. Core's
        // was once named here on its own - true when it was the only one, and
        // silently wrong once Editorial and GED had theirs. The bug that
        // surfaced it: an upload filed into a category the suite had never
        // created, so a test could only pass by asserting the absence of the
        // filing this seeds.
        foreach ($container->get(BootstrapRunner::class)->run() as $result) {
            self::assertTrue($result->success, sprintf('bootstrap failed: %s - %s', $result->label, (string) $result->error));
        }

        $executor->execute([$container->get(AppFixtures::class)], true);

        static::ensureKernelShutdown();
    }
}
