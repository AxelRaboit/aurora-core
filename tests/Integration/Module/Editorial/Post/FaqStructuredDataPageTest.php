<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function array_reverse;
use function bin2hex;
use function json_decode;
use function preg_match;
use function random_bytes;

/**
 * A page's folding list reaches the `<head>` as FAQPage structured data, and
 * only the questions the reader can actually see.
 *
 * Through the served page: what matters is the script that ends up in the
 * head, after the grid has dropped the zones whose dates have passed.
 */
final class FaqStructuredDataPageTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    /** @var list<object> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $entity) {
            $this->entityManager->remove($entity);
        }

        $this->entityManager->flush();
        $this->created = [];

        parent::tearDown();
    }

    public function testTheVisibleQuestionsReachTheHead(): void
    {
        $slug = $this->publish();

        $this->client->request('GET', '/fr/faq-type/'.$slug);

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $match));
        $data = json_decode($match[1], true);

        self::assertSame('FAQPage', $data['@type']);
        self::assertSame(['Faut-il s\'engager ?'], array_column($data['mainEntity'], 'name'));
        self::assertSame('Trois mois, puis sans durée.', $data['mainEntity'][0]['acceptedAnswer']['text']);
    }

    private function publish(): string
    {
        $type = $this->entityManager->getRepository(PostType::class)->findOneBy(['slug' => 'faq-type']);

        if (null === $type) {
            $type = new PostType();
            $type->setSlug('faq-type')->setLabel('FAQ type')->setIcon('file-text')->setHasArchive(false);
            $this->entityManager->persist($type);
            $this->entityManager->flush();
            $this->created[] = $type;
        }

        $post = new Post();
        $post->setPostType($type)
            ->setStatus(PostStatusEnum::Published)
            ->setPublishedAt(new DateTimeImmutable('-1 day'))
            ->setGridLayout(['enabled' => true, 'zones' => [
                ['id' => 'z1', 'type' => 'items', 'display' => 'faq', 'items' => [['id' => 'q1']]],
                // Its dates are over: the page no longer shows it, so the
                // head must not claim it either.
                ['id' => 'z2', 'type' => 'items', 'display' => 'faq', 'visibleUntil' => '2020-01-01', 'items' => [['id' => 'q2']]],
            ]]);

        $slug = 'faq-'.bin2hex(random_bytes(4));
        $post->translate('fr')->setTitle('Questions')->setSlug($slug)->setGrid(['zones' => [
            'z1' => ['items' => ['q1' => ['title' => 'Faut-il s\'engager ?', 'description' => 'Trois mois, puis sans durée.']]],
            'z2' => ['items' => ['q2' => ['title' => 'Promotion passée ?', 'description' => 'Oui.']]],
        ]]);

        $this->entityManager->persist($post);
        $this->entityManager->flush();

        $this->created[] = $post;

        return $slug;
    }
}
