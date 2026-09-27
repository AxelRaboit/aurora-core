<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

use function sprintf;
use function uniqid;

/**
 * Removing the hand-set colour of whole headings, and nothing else.
 */
final class ClearHeadingColourCommandTest extends IntegrationTestCase
{
    private const string ACCENT = '<span class="cdx-text-color" style="color: var(--th-accent)">%s</span>';

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAWholeColouredHeadingFollowsTheThemeAgain(): void
    {
        $post = $this->publish([
            ['type' => 'header', 'data' => ['text' => sprintf(self::ACCENT, 'Présentation'), 'level' => 2]],
            ['type' => 'header', 'data' => ['text' => 'Un titre '.sprintf(self::ACCENT, 'en partie').' coloré', 'level' => 2]],
            ['type' => 'paragraph', 'data' => ['text' => sprintf(self::ACCENT, 'un mot')]],
            ['type' => 'header', 'data' => ['text' => '<span class="cdx-text-color" style="color: #ff0000">Rouge</span>', 'level' => 3]],
        ]);

        $this->launch([]);
        $blocks = $this->reload($post);

        self::assertSame('Présentation', $blocks[0]['data']['text']);
        self::assertSame('Un titre '.sprintf(self::ACCENT, 'en partie').' coloré', $blocks[1]['data']['text'], 'a partly coloured heading is a writing choice');
        self::assertSame(sprintf(self::ACCENT, 'un mot'), $blocks[2]['data']['text'], 'a paragraph is not a heading');
        self::assertStringContainsString('#ff0000', $blocks[3]['data']['text'], 'only the colour asked for is removed');
    }

    public function testADryRunWritesNothing(): void
    {
        $post = $this->publish([
            ['type' => 'header', 'data' => ['text' => sprintf(self::ACCENT, 'Présentation'), 'level' => 2]],
        ]);

        $tester = $this->launch(['--dry-run' => true]);

        self::assertStringContainsString('Nothing was written', $tester->getDisplay());
        self::assertSame(sprintf(self::ACCENT, 'Présentation'), $this->reload($post)[0]['data']['text']);
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private function publish(array $blocks): Post
    {
        $type = new PostType();
        $type->setSlug('headings-'.uniqid())->setLabel('Headings');
        $this->entityManager->persist($type);

        $post = new Post();
        $post->setPostType($type)
            ->setStatus(PostStatusEnum::Published)
            ->setPublishedAt(new DateTimeImmutable('-1 day'));
        $post->translate('fr')->setTitle('Headings')->setSlug('headings-'.uniqid())
            ->setGrid(['z1' => ['blocks' => $blocks]]);

        $this->entityManager->persist($post);
        $this->entityManager->flush();

        return $post;
    }

    /**
     * @param array<string, bool|string> $options
     */
    private function launch(array $options): CommandTester
    {
        $application = new Application(static::$kernel);
        $tester = new CommandTester($application->find('aurora:editorial:headings:clear-colour'));
        $tester->execute($options);

        return $tester;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reload(Post $post): array
    {
        $this->entityManager->clear();

        $fresh = $this->entityManager->find(Post::class, $post->getId());
        self::assertInstanceOf(Post::class, $fresh);

        return $fresh->getTranslation('fr')?->getGrid()['z1']['blocks'] ?? [];
    }
}
