<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration;

use Aurora\Core\DataFixtures\AppFixtures;
use Aurora\Module\Editorial\DataFixtures\EditorialBootstrapFixtures;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

use function class_exists;

abstract class IntegrationTestCase extends WebTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        static::bootKernel();
        $container = static::getContainer();

        $entityManager = $container->get(EntityManagerInterface::class);

        // AppFixtures = core bootstrap (locales, settings, users). The editorial
        // built-in post types/taxonomies moved out of the core AppFixtures into
        // the Editorial module; load them too when it's installed so editorial
        // integration tests still find an 'article' post type.
        $fixtures = [$container->get(AppFixtures::class)];
        if (class_exists(EditorialBootstrapFixtures::class)) {
            $fixtures[] = $container->get(EditorialBootstrapFixtures::class);
        }

        $executor = new ORMExecutor($entityManager, new ORMPurger($entityManager));
        $executor->execute($fixtures);

        static::ensureKernelShutdown();
    }
}
