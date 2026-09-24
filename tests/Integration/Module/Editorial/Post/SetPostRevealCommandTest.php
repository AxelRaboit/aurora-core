<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\Post\Grid\GridNormalizer;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

use function uniqid;

/**
 * La pose en masse de l'effet d'apparition.
 *
 * Le réglage ne se met pas tout seul sur quarante pages publiées avant lui,
 * et ce que la commande doit surtout garantir tient en deux phrases : elle
 * n'écrase jamais l'avis d'une zone, et elle demande les lignes au
 * normaliseur plutôt que de les deviner.
 *
 * La seconde vient d'une vraie erreur : lire `newRow` paraissait suffisant.
 * Il dit qu'une zone *ouvre* une ligne, pas qu'elle en partage une, et une
 * page peut n'en porter aucun tout en plaçant deux zones côte à côte par
 * leurs seules largeurs - ce qui était le cas de toute la démonstration
 * locale. D'où le test d'une paire écrite sans un seul `newRow`.
 */
final class SetPostRevealCommandTest extends IntegrationTestCase
{
    private EntityManagerInterface $entityManager;

    private PostType $type;

    protected function setUp(): void
    {
        parent::setUp();
        static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $this->type = new PostType();
        $this->type->setSlug('reveal-'.uniqid())->setLabel('Reveal');
        $this->entityManager->persist($this->type);
        $this->entityManager->flush();
    }

    public function testThePageEffectIsPosedAndTheZonesGoOnInheriting(): void
    {
        $post = $this->publish([
            ['id' => 'a1', 'type' => 'text', 'span' => ['base' => 48, 'md' => null, 'lg' => 48]],
            ['id' => 'a2', 'type' => 'text', 'span' => ['base' => 48, 'md' => null, 'lg' => 48]],
        ]);

        $this->launch(['--effect' => 'fade']);

        $layout = $this->reload($post);

        self::assertSame('fade', $layout['reveal']);
        self::assertSame('inherit', $layout['zones'][0]['reveal']);
        self::assertSame(
            'inherit',
            $layout['zones'][1]['reveal'],
            'copying the answer down would freeze it into the zone and break the page setting for good',
        );
    }

    public function testTwoZonesSharingARowMeetInTheMiddleWithoutASingleNewRow(): void
    {
        $post = $this->publish([
            ['id' => 'a1', 'type' => 'text', 'span' => ['base' => 48, 'md' => null, 'lg' => 24]],
            ['id' => 'a2', 'type' => 'text', 'span' => ['base' => 48, 'md' => null, 'lg' => 24]],
            ['id' => 'a3', 'type' => 'text', 'span' => ['base' => 48, 'md' => null, 'lg' => 48]],
        ]);

        $this->launch(['--effect' => 'up', '--pairs' => true]);

        $layout = $this->reload($post);

        self::assertSame('up', $layout['reveal']);
        self::assertSame('left', $layout['zones'][0]['reveal']);
        self::assertSame('right', $layout['zones'][1]['reveal']);
        self::assertSame(
            'inherit',
            $layout['zones'][2]['reveal'],
            'a zone alone on its row has no side to come from, so it follows the page',
        );
    }

    public function testAZoneThatHasAnOpinionKeepsIt(): void
    {
        $post = $this->publish([
            ['id' => 'a1', 'type' => 'text', 'span' => ['base' => 48, 'md' => null, 'lg' => 24], 'reveal' => 'zoom'],
            ['id' => 'a2', 'type' => 'text', 'span' => ['base' => 48, 'md' => null, 'lg' => 24]],
        ]);

        $this->launch(['--effect' => 'up', '--pairs' => true]);

        $layout = $this->reload($post);

        self::assertSame('zoom', $layout['zones'][0]['reveal'], 'rerunning the command must not undo an author');
        self::assertSame('right', $layout['zones'][1]['reveal']);
    }

    public function testADryRunWritesNothing(): void
    {
        $post = $this->publish([
            ['id' => 'a1', 'type' => 'text', 'span' => ['base' => 48, 'md' => null, 'lg' => 48]],
        ]);

        $this->launch(['--effect' => 'blur', '--dry-run' => true]);

        self::assertSame('none', $this->reload($post)['reveal']);
    }

    public function testAnInventedEffectIsRefusedBeforeAnythingIsRead(): void
    {
        $tester = $this->launch(['--effect' => 'explosion']);

        self::assertSame(2, $tester->getStatusCode(), 'Command::INVALID');
    }

    /**
     * @param list<array<string, mixed>> $zones
     */
    private function publish(array $zones): Post
    {
        $normalizer = static::getContainer()->get(GridNormalizer::class);

        $post = new Post();
        $post->setPostType($this->type)
            ->setStatus(PostStatusEnum::Published)
            ->setPublishedAt(new DateTimeImmutable('-1 day'))
            ->setGridLayout($normalizer->normalizeLayout([
                'enabled' => true,
                'snap' => 4,
                'zones' => $zones,
            ]));

        $post->translate('fr')->setTitle('Reveal')->setSlug('reveal-'.uniqid());

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
        $tester = new CommandTester($application->find('aurora:editorial:reveal'));
        $tester->execute(['--type' => [$this->type->getSlug()], ...$options]);

        return $tester;
    }

    /**
     * @return array<string, mixed>
     */
    private function reload(Post $post): array
    {
        $this->entityManager->clear();

        $fresh = $this->entityManager->find(Post::class, $post->getId());
        self::assertInstanceOf(Post::class, $fresh);

        return $fresh->getGridLayout();
    }
}
