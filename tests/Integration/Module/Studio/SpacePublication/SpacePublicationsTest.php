<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\SpacePublication;

use Aurora\Module\Editorial\Post\Dto\PostInputFactoryInterface;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\Post\Enum\PostVisibilityEnum;
use Aurora\Module\Editorial\Post\Manager\PostManagerInterface;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Manager\CustomerSpaceManagerInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLink;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function bin2hex;
use function json_decode;
use function parse_url;
use function random_bytes;
use function sprintf;

use const PHP_URL_PATH;

/**
 * A client's documents: publications written from their space, delivered by
 * link, and read by the client in their space.
 *
 * Three promises are checked here. A document started from a space is the
 * client's - a draft, shared by link only, prepared for them by name - and it
 * stays off the site whatever the editor sends. The client reads it from the
 * space's own link, once published, and never a document of another space.
 * And deleting the space leaves the document standing.
 */
final class SpacePublicationsTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    /** @var list<object> */
    private array $created = [];

    private string $suffix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->suffix = bin2hex(random_bytes(4));

        $admin = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');
    }

    protected function tearDown(): void
    {
        $this->entityManager->clear();

        foreach (array_reverse($this->created) as $entity) {
            // A space the test deleted itself has no identifier left.
            if (null === $entity->getId()) {
                continue;
            }

            $managed = $this->entityManager->find($entity::class, $entity->getId());

            if (null !== $managed) {
                $this->entityManager->remove($managed);
            }
        }

        $this->entityManager->flush();
        $this->created = [];

        parent::tearDown();
    }

    public function testADocumentStartedFromASpaceIsTheClients(): void
    {
        $space = $this->space('Boulangerie '.$this->suffix);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/publications/create', $space->getId()), [
            'title' => 'Audit de présence '.$this->suffix,
        ]);
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertCount(1, $data['publications']);
        self::assertStringContainsString('/backend/editorial/posts/', $data['editPath']);

        $post = static::getContainer()->get(PostRepository::class)->find($data['publications'][0]['id']);
        self::assertInstanceOf(Post::class, $post);
        $this->created[] = $post;

        self::assertSame($space->getId(), $post->getCustomerSpaceId());
        self::assertSame(PostStatusEnum::Draft, $post->getStatus());
        self::assertSame(PostVisibilityEnum::Link, $post->getVisibility());
        self::assertSame('Boulangerie '.$this->suffix, $post->getReadingPage()['preparedFor']);
        self::assertFalse($post->isCommentsEnabled());
        self::assertFalse($post->isShareEnabled());
    }

    public function testADocumentNeedsATitle(): void
    {
        $space = $this->space('Boulangerie '.$this->suffix);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/publications/create', $space->getId()), ['title' => '  ']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], static::getContainer()->get(PostRepository::class)->findForCustomerSpace((int) $space->getId()));
    }

    /** The select is locked on screen; this is what makes it so. */
    public function testAClientsDocumentStaysOffTheSiteWhateverTheEditorSends(): void
    {
        $space = $this->space('Boulangerie '.$this->suffix);
        $post = $this->document($space, 'Audit '.$this->suffix, PostStatusEnum::Published);

        $container = static::getContainer();
        $container->get(PostManagerInterface::class)->update($post, $container->get(PostInputFactoryInterface::class)->fromArray([
            'postTypeId' => $post->getPostType()->getId(),
            'status' => 'published',
            'visibility' => 'site',
            'translations' => ['fr' => ['title' => 'Audit '.$this->suffix, 'slug' => 'audit-'.$this->suffix]],
        ]));
        $this->entityManager->flush();

        self::assertSame(PostVisibilityEnum::Link, $post->getVisibility());
        self::assertFalse($post->isOnSite());
    }

    public function testTheSpaceListsItsDocuments(): void
    {
        $space = $this->space('Boulangerie '.$this->suffix);
        $this->document($space, 'Strategie '.$this->suffix);

        $this->client->request('GET', sprintf('/workspace/%d?view=publications', $space->getId()));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Strategie '.$this->suffix, (string) $this->client->getResponse()->getContent());
    }

    /**
     * Published, the client reads it from the space's link; a draft is the
     * team's, and a document of another space is not theirs to read.
     */
    public function testTheClientReadsItsPublishedDocumentsAndNoOther(): void
    {
        $space = $this->space('Boulangerie '.$this->suffix);
        $other = $this->space('Fromagerie '.$this->suffix);
        $published = $this->document($space, 'Audit publie '.$this->suffix, PostStatusEnum::Published);
        $draft = $this->document($space, 'Audit brouillon '.$this->suffix);
        $foreign = $this->document($other, 'Audit voisin '.$this->suffix, PostStatusEnum::Published);

        $path = $this->spaceLink($space);
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Audit publie '.$this->suffix, $body);
        self::assertStringNotContainsString('Audit brouillon '.$this->suffix, $body);
        self::assertStringNotContainsString('Audit voisin '.$this->suffix, $body);

        $this->client->request('GET', $path.'/documents/'.$published->getId());
        self::assertResponseIsSuccessful();
        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Audit publie '.$this->suffix, $page);
        self::assertStringContainsString('Préparé pour Boulangerie '.$this->suffix, $page);
        // The way back to the space, which a link received by mail has not.
        self::assertStringContainsString('href="'.$path.'"', $page);
        self::assertSame('no-referrer', $this->client->getResponse()->headers->get('Referrer-Policy'));

        foreach ([$draft, $foreign] as $refused) {
            $this->client->request('GET', $path.'/documents/'.$refused->getId());
            self::assertResponseStatusCodeSame(404);
        }
    }

    /** A deliverable the client may still hold a link to outlives the space. */
    public function testDeletingTheSpaceLeavesItsDocumentsStanding(): void
    {
        $space = $this->space('Boulangerie '.$this->suffix);
        $post = $this->document($space, 'Audit '.$this->suffix, PostStatusEnum::Published);
        $postId = $post->getId();

        // Deleted the way the screen deletes it, through the manager and with
        // the manager's own entity manager, which is the current container's.
        $container = static::getContainer();
        $live = $container->get(EntityManagerInterface::class)->find(CustomerSpace::class, $space->getId());
        self::assertInstanceOf(CustomerSpace::class, $live);
        $container->get(CustomerSpaceManagerInterface::class)->delete($live);

        $this->entityManager->clear();
        $kept = $this->entityManager->find(Post::class, $postId);
        self::assertInstanceOf(Post::class, $kept);
        self::assertNull($kept->getCustomerSpaceId());
        self::assertSame(PostVisibilityEnum::Link, $kept->getVisibility());
    }

    private function space(string $customerName): CustomerSpace
    {
        $customer = new Customer();
        $customer->setLegalName($customerName)->setContractualEmail('doc@example.test');
        $this->persist($customer);

        $this->client->jsonRequest('POST', '/backend/studio/spaces/create', [
            'name' => 'Espace '.$customerName,
            'customerId' => $customer->getId(),
        ]);
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $space = $this->entityManager->getRepository(CustomerSpace::class)->find($data['space']['id']);
        self::assertInstanceOf(CustomerSpace::class, $space);
        // Removed before its customer: the space holds the customer.
        $this->created[] = $space;

        return $space;
    }

    private function document(CustomerSpace $space, string $title, PostStatusEnum $status = PostStatusEnum::Draft): Post
    {
        // Read through this test's own manager: the client reboots the kernel
        // on every request, and an entity from the new container's manager
        // is unknown to the one persisting here.
        $type = $this->entityManager->getRepository(PostType::class)->findOneBy(['slug' => 'page']);
        self::assertInstanceOf(PostType::class, $type);

        $post = new Post();
        $post->setPostType($type)->setStatus($status)->setCustomerSpaceId((int) $space->getId());
        if (PostStatusEnum::Published === $status) {
            $post->setPublishedAt(new DateTimeImmutable('-1 day'));
        }
        $post->setReadingPage(['preparedFor' => $space->getCustomer()->getLegalName()]);
        $post->translate('fr')->setTitle($title)->setSlug('d-'.bin2hex(random_bytes(4)));
        $this->persist($post);

        return $post;
    }

    /** The space's own address, as the client receives it. */
    private function spaceLink(CustomerSpace $space): string
    {
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/access/issue', $space->getId()), [
            'recipientEmail' => 'client@example.test',
            'label' => 'Le client',
        ]);
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        foreach ($this->entityManager->getRepository(SpaceAccessLink::class)->findBy(['space' => $space]) as $link) {
            $this->created[] = $link;
        }

        return (string) parse_url($data['url'], PHP_URL_PATH);
    }

    private function persist(object $entity): void
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
        $this->created[] = $entity;
    }
}
