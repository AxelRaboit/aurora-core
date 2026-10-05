<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial;

use Aurora\Module\Editorial\EditorialBootstrapProvider;
use Aurora\Module\Editorial\PostType\Entity\PostTypeInterface;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function dirname;
use function file_get_contents;
use function iterator_to_array;
use function preg_match_all;

/**
 * The names the installer gives the built-in post types and taxonomies.
 *
 * They are translation keys resolved at install. The block that held them
 * slipped under `suite.parameters` in the YAML, every key stopped
 * resolving, and a fresh installation stored the keys themselves - shown as
 * the name of its categories, its tags, its pages and articles.
 */
final class EditorialBootstrapLabelsTest extends IntegrationTestCase
{
    public function testEveryLabelTheInstallerUsesResolvesInEveryLanguage(): void
    {
        static::bootKernel();
        $translator = static::getContainer()->get(TranslatorInterface::class);
        $source = (string) file_get_contents(dirname(__DIR__, 4).'/src/Module/Editorial/EditorialBootstrapProvider.php');

        self::assertGreaterThan(0, preg_match_all("/'(suite\\.editorial\\.bootstrap\\.[a-z_.]+)'/", $source, $keys));

        foreach ($keys[1] as $key) {
            foreach (['fr', 'en', 'es'] as $locale) {
                self::assertNotSame($key, $translator->trans($key, [], 'messages', $locale), "$key has no $locale translation");
            }
        }
    }

    /** An installation that stored a raw key gets its label back on the next install. */
    public function testALabelLeftOnItsKeyIsRepairedByTheInstaller(): void
    {
        static::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $page = static::getContainer()->get(PostTypeRepository::class)->findOneBySlug('page');
        self::assertInstanceOf(PostTypeInterface::class, $page);
        $original = $page->getLabel();

        $page->setLabel('suite.editorial.bootstrap.post_types.page');
        $entityManager->flush();

        iterator_to_array(static::getContainer()->get(EditorialBootstrapProvider::class)->bootstrap(), false);

        try {
            self::assertSame('Page', $page->getLabel());
        } finally {
            $page->setLabel($original);
            $entityManager->flush();
        }
    }

    /** A label somebody typed is never touched. */
    public function testALabelSomebodyTypedIsLeftAlone(): void
    {
        static::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $page = static::getContainer()->get(PostTypeRepository::class)->findOneBySlug('page');
        self::assertInstanceOf(PostTypeInterface::class, $page);
        $original = $page->getLabel();

        $page->setLabel('Pages du site');
        $entityManager->flush();

        iterator_to_array(static::getContainer()->get(EditorialBootstrapProvider::class)->bootstrap(), false);

        try {
            self::assertSame('Pages du site', $page->getLabel());
        } finally {
            $page->setLabel($original);
            $entityManager->flush();
        }
    }
}
