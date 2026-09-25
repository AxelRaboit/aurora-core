<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Poll;

use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A reader's vote on a poll zone: counted once, only for a poll that exists.
 */
final class PollVoteTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAVoteIsCountedOnceWhateverTheClicks(): void
    {
        $postId = $this->poll(PostStatusEnum::Published);

        $first = $this->vote($postId, 'p1', 1);
        $second = $this->vote($postId, 'p1', 0);

        self::assertSame(1, $first['total']);
        self::assertSame(100, $first['answers'][1]['percent']);
        self::assertSame(1, $second['total'], 'the same reader voting again changes nothing');
        self::assertSame(1, $second['answers'][1]['votes']);
    }

    public function testAnAnswerThePollDoesNotOfferIsRefused(): void
    {
        $postId = $this->poll(PostStatusEnum::Published);

        self::assertFalse($this->vote($postId, 'p1', 5)['success']);
        self::assertFalse($this->vote($postId, 'not-a-poll', 0)['success']);
    }

    public function testADraftTakesNoVote(): void
    {
        self::assertFalse($this->vote($this->poll(PostStatusEnum::Draft), 'p1', 0)['success']);
    }

    /** @return array<string, mixed> */
    private function vote(int $postId, string $zoneId, int $answer): array
    {
        $this->client->request('POST', sprintf('/fr/poll/%d/%s', $postId, $zoneId), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ], content: json_encode(['answer' => $answer], JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function poll(PostStatusEnum $status): int
    {
        $type = $this->entityManager->getRepository(PostType::class)->findOneBy(['slug' => 'sondage-test']);

        if (null === $type) {
            $type = new PostType();
            $type->setSlug('sondage-test')->setLabel('Sondage')->setIcon('file-text')->setHasArchive(false);
            $this->entityManager->persist($type);
        }

        $post = new Post();
        $post->setPostType($type)
            ->setStatus($status)
            ->setPublishedAt(new DateTimeImmutable('-1 day'))
            ->setGridLayout(['enabled' => true, 'zones' => [['id' => 'p1', 'type' => 'poll']]]);

        $post->translate('fr')
            ->setTitle('Sondage')
            ->setSlug('sondage-'.bin2hex(random_bytes(4)))
            ->setGrid(['zones' => ['p1' => ['label' => 'Quel format ?', 'code' => "Réels\nCarrousels"]]]);

        $this->entityManager->persist($post);
        $this->entityManager->flush();

        return (int) $post->getId();
    }
}
