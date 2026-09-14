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

use function sprintf;
use function uniqid;

/**
 * The command exists to be run once, on production, before the withholding
 * is deployed - so the thing worth testing is that it actually finds a
 * reference, not that it prints a table.
 *
 * A command that answered "nothing to report" because its query was wrong
 * would be worse than no command: it is read by somebody deciding whether it
 * is safe to deploy.
 *
 * The cases assert on **titles** rather than on ids, and that is not a matter
 * of taste. A document id is two or three digits, the report prints titles
 * carrying a `uniqid()`, and `assertStringContainsString` does not care which
 * column it matched in: the day an id was 78 and a neighbouring title happened
 * to read `6aa784ab225fe`, the suite went red on a command that was working.
 * A title is unique by construction here, so it cannot collide.
 */
final class AuditPublicDocumentsCommandTest extends IntegrationTestCase
{
    public function testItReportsADraftPictureLaidOutInAPublishedPost(): void
    {
        static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $title = 'Visuel '.uniqid();

        $draft = new Document();
        $draft->setTitle($title)
            ->setStatus(DocumentStatusEnum::Draft)
            ->setFilePath('ged/1999/04/visuel-'.uniqid().'.png');
        $entityManager->persist($draft);
        $entityManager->flush();

        $post = new Post();
        $post->setPostType($this->postType($entityManager))
            ->setStatus(PostStatusEnum::Published)
            // The shape the banner editor really writes. `id` names the
            // block ("banner-text"); the picture is `mediaId`. The first
            // version of this test used `id` and passed against a command
            // that collected nothing.
            ->setBannerLayout(['items' => [['id' => 'banner-text', 'mediaId' => $draft->getId()]]]);
        $entityManager->persist($post);
        $entityManager->flush();

        $output = $this->audit();

        self::assertStringContainsString($title, $output);
        self::assertStringContainsString('draft', $output);
        self::assertStringContainsString('bandeau', $output);
    }

    public function testAPublishedPictureIsNotReported(): void
    {
        static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $title = 'Publié '.uniqid();

        $published = new Document();
        $published->setTitle($title)
            ->setStatus(DocumentStatusEnum::Published)
            ->setFilePath('ged/1999/05/publie-'.uniqid().'.png');
        $entityManager->persist($published);

        $post = new Post();
        $post->setPostType($this->postType($entityManager))
            ->setStatus(PostStatusEnum::Published)
            ->setGridLayout(['zones' => [['mediaId' => $published->getId()]]]);
        $entityManager->persist($post);
        $entityManager->flush();

        self::assertStringNotContainsString($title, $this->audit());
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

        $title = 'Brouillon '.uniqid();

        $draft = new Document();
        $draft->setTitle($title)
            ->setStatus(DocumentStatusEnum::Draft)
            ->setFilePath('ged/1999/06/brouillon-'.uniqid().'.png');
        $entityManager->persist($draft);

        $post = new Post();
        $post->setPostType($this->postType($entityManager))
            ->setStatus(PostStatusEnum::Draft)
            ->setGalleryLayout(['items' => [['id' => 'shot-1', 'mediaId' => $draft->getId()]]]);
        $entityManager->persist($post);
        $entityManager->flush();

        self::assertStringNotContainsString($title, $this->audit());
    }

    /**
     * A gallery zone names many pictures at once, under `mediaIds`, and a
     * banner carries its logo under `logoMediaId`. Both were missed by the
     * first version of this command, which is why they have their own case.
     */
    public function testItReadsGalleryListsAndTheBannerLogo(): void
    {
        static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $listedTitle = 'Dans une liste '.uniqid();

        $inAList = new Document();
        $inAList->setTitle($listedTitle)
            ->setStatus(DocumentStatusEnum::Draft)
            ->setFilePath('ged/1999/07/liste-'.uniqid().'.png');
        $entityManager->persist($inAList);

        $logoTitle = 'Logo '.uniqid();

        $logo = new Document();
        $logo->setTitle($logoTitle)
            ->setStatus(DocumentStatusEnum::Draft)
            ->setFilePath('ged/1999/07/logo-'.uniqid().'.png');
        $entityManager->persist($logo);
        $entityManager->flush();

        $post = new Post();
        $post->setPostType($this->postType($entityManager))
            ->setStatus(PostStatusEnum::Published)
            ->setGridLayout(['zones' => [['mediaIds' => [$inAList->getId()]]]])
            ->setBannerLayout(['logoMediaId' => $logo->getId()]);
        $entityManager->persist($post);
        $entityManager->flush();

        $output = $this->audit();

        self::assertStringContainsString($listedTitle, $output);
        self::assertStringContainsString($logoTitle, $output);
    }

    /**
     * The block identifiers themselves are not documents. A command that
     * treated them as ids would report rows that name nothing.
     */
    public function testBlockIdentifiersAreNotMistakenForDocuments(): void
    {
        static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $post = new Post();
        $post->setPostType($this->postType($entityManager))
            ->setStatus(PostStatusEnum::Published)
            ->setGalleryLayout(['items' => [['id' => 'shot-1'], ['id' => 'shot-2']]]);
        $entityManager->persist($post);
        $entityManager->flush();

        // Scoped to this post: the rows the other cases created are still in
        // the database, so asserting the report is empty would assert the
        // order tests run in.
        self::assertStringNotContainsString(
            sprintf('publication #%d', (int) $post->getId()),
            $this->audit(),
        );
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
