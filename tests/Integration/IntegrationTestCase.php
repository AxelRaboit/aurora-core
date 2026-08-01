<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration;

use Aurora\Core\Bootstrap\CoreBootstrapProvider;
use Aurora\Core\DataFixtures\AppFixtures;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class IntegrationTestCase extends WebTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        static::bootKernel();
        $container = static::getContainer();

        $entityManager = $container->get(EntityManagerInterface::class);

        // Purge first, then seed the mandatory rows, then the dev accounts.
        // The bootstrap providers run here rather than being duplicated into a
        // fixture: a test database that is seeded differently from a real
        // install is a test database that can hide breakage — this suite went
        // red precisely because the locales and the theme moved, which is the
        // signal working as intended.
        $executor = new ORMExecutor($entityManager, new ORMPurger($entityManager));
        $executor->purge();

        // Core's provider by name: this package's own suite has no module
        // providers to collect, and reaching for the tagged iterator would only
        // add indirection. A module package's tests seed their own alongside it.
        foreach ($container->get(CoreBootstrapProvider::class)->bootstrap() as $_) {
            // Drain the generator; the labels only matter to the command.
        }

        $executor->execute([$container->get(AppFixtures::class)], true);

        static::ensureKernelShutdown();
    }
}
