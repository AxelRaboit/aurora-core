<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Form\Entity\Form;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\Post\Enum\PostVisibilityEnum;
use Aurora\Module\Editorial\Post\Grid\GridNormalizer;
use Aurora\Module\Editorial\Post\Reading\Entity\PostReadingLink;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function bin2hex;
use function random_bytes;
use function sprintf;

/**
 * The zones of a grid that talk back to the server, on a reading page.
 *
 * Two of them reach an address of their own. A form posts to the form's
 * route, which knows nothing of the publication it stands on, so it works
 * wherever the publication is read. The comments zone builds its addresses
 * from the publication's address on the site - which a publication shared by
 * link does not have - so on a reading page it drew a thread that could only
 * fail, and printed that site address in the page. It is left out there, as
 * the thread at the foot of the page already is.
 */
final class PostReadingZonesTest extends IntegrationTestCase
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
        $this->entityManager->clear();

        foreach (array_reverse($this->created) as $entity) {
            $managed = $this->entityManager->find($entity::class, $entity->getId());

            if (null !== $managed) {
                $this->entityManager->remove($managed);
            }
        }

        $this->entityManager->flush();
        $this->created = [];

        parent::tearDown();
    }

    public function testAFormZoneIsDrawnAndPostsToTheFormsOwnRoute(): void
    {
        $slug = 'questionnaire-'.bin2hex(random_bytes(4));
        $form = new Form();
        $form->setActive(true);
        $form->translate('fr')->setTitle('Votre avis sur cet audit')->setSlug($slug);
        $this->persist($form);

        $post = $this->post([['id' => 'ask', 'type' => GridNormalizer::ZONE_FORM, 'formId' => $form->getId()]]);

        $this->client->request('GET', '/read/'.$this->link($post)->getToken());

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Votre avis sur cet audit', $body);
        self::assertStringContainsString('FormRender', $body);
        // Inside the component's JSON props, where slashes are escaped.
        self::assertStringContainsString(sprintf('\\/fr\\/forms\\/%s', $slug), $body);
    }

    /**
     * Nested in a stack too: a thread tucked into a column is still a thread
     * pointing at an address the publication does not have.
     */
    public function testACommentsZoneIsLeftOutOfTheReadingPage(): void
    {
        $zones = [
            ['id' => 'talk', 'type' => GridNormalizer::ZONE_COMMENTS],
            ['id' => 'column', 'type' => GridNormalizer::ZONE_STACK, 'children' => [['id' => 'nested', 'type' => GridNormalizer::ZONE_COMMENTS]]],
        ];
        $post = $this->post($zones, PostVisibilityEnum::Site);
        $slug = (string) $post->getTranslation('fr')?->getSlug();

        // On the site, the thread is there: the zone works where it was made for.
        $this->client->request('GET', '/fr/page/'.$slug);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('PostComments', (string) $this->client->getResponse()->getContent());

        // Through a reading link, it is not, and the site's address is not printed.
        $this->client->request('GET', '/read/'.$this->link($post)->getToken());
        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('PostComments', $body);
        self::assertStringNotContainsString($slug.'/comments', $body);
    }

    /** @param list<array<string, mixed>> $zones */
    private function post(array $zones, PostVisibilityEnum $visibility = PostVisibilityEnum::Link): Post
    {
        $type = $this->entityManager->getRepository(PostType::class)->findOneBy(['slug' => 'page']);
        self::assertInstanceOf(PostType::class, $type);

        $post = new Post();
        $post->setPostType($type)
            ->setStatus(PostStatusEnum::Published)
            ->setVisibility($visibility)
            ->setPublishedAt(new DateTimeImmutable('-1 day'))
            ->setCommentsEnabled(true)
            ->setGridLayout(static::getContainer()->get(GridNormalizer::class)->normalizeLayout(['enabled' => true, 'zones' => $zones]));
        $post->translate('fr')->setTitle('Audit '.bin2hex(random_bytes(3)))->setSlug('z-'.bin2hex(random_bytes(4)));
        $this->persist($post);

        return $post;
    }

    private function link(Post $post): PostReadingLink
    {
        $link = new PostReadingLink($post);
        $this->persist($link);

        return $link;
    }

    private function persist(object $entity): void
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
        $this->created[] = $entity;
    }
}
