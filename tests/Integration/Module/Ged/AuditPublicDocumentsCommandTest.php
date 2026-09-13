<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Ged;

use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

use function uniqid;

/**
 * The command exists to be run once, on production, before the withholding
 * is deployed - so the thing worth testing is that it actually finds a
 * reference, not that it prints a table.
 *
 * A command that answered "nothing to report" because its query was wrong
 * would be worse than no command: it is read by somebody deciding whether it
 * is safe to deploy.
 */
final class AuditPublicDocumentsCommandTest extends IntegrationTestCase
{
    public function testItReportsADraftPictureLaidOutInAPublishedPost(): void
    {
        static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $draft = new Document();
        $draft->setTitle('Visuel '.uniqid())
            ->setStatus(DocumentStatusEnum::Draft)
            ->setFilePath('ged/1999/04/visuel-'.uniqid().'.png');
        $entityManager->persist($draft);
        $entityManager->flush();

        $post = new Post();
        $post->setPostType($this->postType($entityManager))
            ->setStatus(PostStatusEnum::Published)
            // The shape the banner editor writes: pictures nested under
            // zones, each one carrying the document's id.
            ->setBannerLayout(['zones' => [['items' => [['id' => $draft->getId()]]]]]);
        $entityManager->persist($post);
        $entityManager->flush();

        $output = $this->audit();

        self::assertStringContainsString((string) $draft->getId(), $output);
        self::assertStringContainsString('draft', $output);
        self::assertStringContainsString('bandeau', $output);
    }

    public function testAPublishedPictureIsNotReported(): void
    {
        static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $published = new Document();
        $published->setTitle('Publié '.uniqid())
            ->setStatus(DocumentStatusEnum::Published)
            ->setFilePath('ged/1999/05/publie-'.uniqid().'.png');
        $entityManager->persist($published);

        $post = new Post();
        $post->setPostType($this->postType($entityManager))
            ->setStatus(PostStatusEnum::Published)
            ->setGridLayout(['rows' => [['items' => [['id' => $published->getId()]]]]]);
        $entityManager->persist($post);
        $entityManager->flush();

        self::assertStringNotContainsString((string) $published->getId(), $this->audit());
    }

    /**
     * A draft laid out in a post that is itself a draft is nobody's problem:
     * no public page renders it, so nothing disappears when it stops being
     * served. Reporting it would bury the lines that matter.
     */
    public function testADraftPostIsNotWalked(): void
    {
        static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $draft = new Document();
        $draft->setTitle('Brouillon '.uniqid())
            ->setStatus(DocumentStatusEnum::Draft)
            ->setFilePath('ged/1999/06/brouillon-'.uniqid().'.png');
        $entityManager->persist($draft);

        $post = new Post();
        $post->setPostType($this->postType($entityManager))
            ->setStatus(PostStatusEnum::Draft)
            ->setGalleryLayout(['items' => [['id' => $draft->getId()]]]);
        $entityManager->persist($post);
        $entityManager->flush();

        self::assertStringNotContainsString((string) $draft->getId(), $this->audit());
    }

    private function postType(EntityManagerInterface $entityManager): PostType
    {
        $type = $entityManager->getRepository(PostType::class)->findOneBy(['slug' => 'audit-type']);

        if (!$type instanceof PostType) {
            $type = new PostType();
            $type->setSlug('audit-type')->setLabel('Audit type')->setIcon('file-text')->setHasArchive(false);
            $entityManager->persist($type);
            $entityManager->flush();
        }

        return $type;
    }

    private function audit(): string
    {
        $tester = new CommandTester(
            new Application(static::$kernel)->find('aurora:ged:audit-public-documents'),
        );
        $tester->execute([]);

        return $tester->getDisplay();
    }
}
