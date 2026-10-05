<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\Post\Enum\PostVisibilityEnum;
use Aurora\Module\Editorial\Post\Preview\Manager\PostPreviewTokenManagerInterface;
use Aurora\Module\Editorial\Post\Reading\Entity\PostReadingLink;
use Aurora\Module\Editorial\Post\Reading\Repository\PostReadingLinkRepository;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function bin2hex;
use function json_decode;
use function password_hash;
use function random_bytes;

use const PASSWORD_DEFAULT;

/**
 * What a reading link lets somebody read, and what it must not.
 *
 * The interesting assertions are the negative ones, as on a deck's link. A
 * link is a secret handed to one person: each way it can stop working has to
 * stop it, a draft must never open, and a locked link must say nothing of what
 * it guards. The page itself is the publication without the site: no menu, no
 * canonical address on the site, and a header of its own.
 */
final class PostReadingLinkTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    /** @var list<object> */
    private array $created = [];

    private string $suffix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->suffix = bin2hex(random_bytes(4));
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

    public function testALinkOpensAPublicationSharedByLinkWithoutTheSite(): void
    {
        $post = $this->post('Audit de présence '.$this->suffix);
        $post->setReadingPage(['preparedFor' => 'Maison Durand']);

        $this->entityManager->flush();

        $this->client->request('GET', '/read/'.$this->link($post)->getToken());

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Audit de présence '.$this->suffix, $body);
        self::assertStringContainsString('Préparé pour Maison Durand', $body);
        // Received by mail, it has no space to lead back to.
        self::assertStringNotContainsString('/spaces/', $body);
        // The canonical names the page itself, never its address under the
        // site: for a publication shared by link that one answers 404.
        $slug = (string) $post->getTranslation('fr')?->getSlug();
        self::assertStringNotContainsString('/fr/page/'.$slug, $body);
        self::assertStringContainsString('noindex', $body);
    }

    /** The address is the whole secret, so no answer to it may leak it. */
    public function testTheAddressNeverLeaks(): void
    {
        $post = $this->post('Audit '.$this->suffix);

        $this->client->request('GET', '/read/'.$this->link($post)->getToken());
        $this->assertKeptPrivate('the page');

        $locked = $this->link($post, 'mot-de-passe-'.$this->suffix);
        $this->client->request('GET', '/read/'.$locked->getToken());
        $this->assertKeptPrivate('the door');

        $this->client->request('POST', '/read/'.$locked->getToken().'/unlock', ['password' => 'faux']);
        $this->assertKeptPrivate('the door after a wrong password');

        $this->client->request('POST', '/read/'.$locked->getToken().'/unlock', ['password' => 'mot-de-passe-'.$this->suffix]);
        self::assertResponseRedirects();
        $this->assertKeptPrivate('the redirect after the right password');
    }

    public function testARevokedLinkIsRefused(): void
    {
        $link = $this->link($this->post('Audit '.$this->suffix));
        $link->revoke(new DateTimeImmutable());

        $this->entityManager->flush();

        $this->client->request('GET', '/read/'.$link->getToken());

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnExpiredLinkIsRefused(): void
    {
        $link = $this->link($this->post('Audit '.$this->suffix));
        $link->setExpiresAt(new DateTimeImmutable('-1 minute'));

        $this->entityManager->flush();

        $this->client->request('GET', '/read/'.$link->getToken());

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnUnknownTokenIs404(): void
    {
        $this->client->request('GET', '/read/'.bin2hex(random_bytes(32)));

        self::assertResponseStatusCodeSame(404);
    }

    /** A draft never opens through a link: the preview is for that, and it expires. */
    public function testADraftOrATrashedPublicationOpensNothing(): void
    {
        $draft = $this->post('Brouillon '.$this->suffix);
        $draft->setStatus(PostStatusEnum::Draft);

        $trashed = $this->post('Corbeille '.$this->suffix);
        $trashed->setDeletedAt(new DateTimeImmutable());

        $this->entityManager->flush();

        foreach ([$draft, $trashed] as $post) {
            $this->client->request('GET', '/read/'.$this->link($post)->getToken());
            self::assertResponseStatusCodeSame(404);
        }
    }

    /** A page of the site may be sent by link too, and reads the same way. */
    public function testAPageOfTheSiteOpensInTheReadingLayout(): void
    {
        $post = $this->post('Page du site '.$this->suffix, PostVisibilityEnum::Site);

        $this->client->request('GET', '/read/'.$this->link($post)->getToken());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Ce document vous a été transmis personnellement', (string) $this->client->getResponse()->getContent());
    }

    public function testOpeningTheLinkIsCounted(): void
    {
        $link = $this->link($this->post('Audit '.$this->suffix));

        $this->client->request('GET', '/read/'.$link->getToken());
        $this->client->request('GET', '/read/'.$link->getToken());

        self::assertSame(2, $this->reload($link->getToken())->getOpenCount());
        self::assertNotNull($this->reload($link->getToken())->getLastUsedAt());
    }

    public function testItOpensInTheLanguageAskedForWhenWrittenInIt(): void
    {
        $post = $this->post('Audit '.$this->suffix);
        $post->translate('en')->setTitle('Audit in English '.$this->suffix)->setSlug('audit-en-'.$this->suffix);
        $this->entityManager->flush();
        $link = $this->link($post);

        $this->client->request('GET', '/read/'.$link->getToken().'?locale=en');
        self::assertStringContainsString('Audit in English '.$this->suffix, (string) $this->client->getResponse()->getContent());

        // A language it is not written in falls back rather than failing.
        $this->client->request('GET', '/read/'.$link->getToken().'?locale=es');
        self::assertResponseIsSuccessful();
    }

    /**
     * A protected link shows a door, and the door says nothing of the
     * publication: not its title, not who it was prepared for.
     */
    public function testAProtectedLinkAsksForItsPasswordAndNamesNothing(): void
    {
        $post = $this->post('Audit secret '.$this->suffix);
        $post->setReadingPage(['preparedFor' => 'Client discret']);

        $this->entityManager->flush();
        $link = $this->link($post, 'phrase-'.$this->suffix);

        $this->client->request('GET', '/read/'.$link->getToken());

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Audit secret', $body);
        self::assertStringNotContainsString('Client discret', $body);
        self::assertStringContainsString('type="password"', $body);
        self::assertSame(0, $this->reload($link->getToken())->getOpenCount(), 'a door is not an opening');
    }

    public function testTheRightPasswordOpensItAndTheSessionRemembers(): void
    {
        $link = $this->link($this->post('Audit secret '.$this->suffix), 'phrase-'.$this->suffix);

        $this->client->request('POST', '/read/'.$link->getToken().'/unlock', ['password' => 'phrase-'.$this->suffix]);
        self::assertResponseRedirects('/read/'.$link->getToken());

        $this->client->followRedirect();
        self::assertStringContainsString('Audit secret '.$this->suffix, (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/read/'.$link->getToken());
        self::assertStringContainsString('Audit secret '.$this->suffix, (string) $this->client->getResponse()->getContent());
    }

    public function testAWrongPasswordOpensNothingAndSaysNothingMore(): void
    {
        $link = $this->link($this->post('Audit secret '.$this->suffix), 'phrase-'.$this->suffix);

        $this->client->request('POST', '/read/'.$link->getToken().'/unlock', ['password' => 'phrase-'.$this->suffix.'-faux']);

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Audit secret', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->reload($link->getToken())->getOpenCount());
    }

    public function testUnlockingOneLinkDoesNotUnlockAnother(): void
    {
        $post = $this->post('Audit secret '.$this->suffix);
        $first = $this->link($post, 'phrase-'.$this->suffix);
        $second = $this->link($post, 'autre-'.$this->suffix);

        $this->client->request('POST', '/read/'.$first->getToken().'/unlock', ['password' => 'phrase-'.$this->suffix]);
        $this->client->request('GET', '/read/'.$second->getToken());

        self::assertStringNotContainsString('Audit secret', (string) $this->client->getResponse()->getContent());
    }

    /** The preview of a publication shared by link shows the reader's page, not the site's. */
    public function testThePreviewOfAPublicationSharedByLinkUsesTheReadingLayout(): void
    {
        $post = $this->post('Audit en relecture '.$this->suffix);
        $post->setStatus(PostStatusEnum::Draft);

        $this->entityManager->flush();

        $token = self::getContainer()->get(PostPreviewTokenManagerInterface::class)->resolveOrCreate($post, $this->admin());
        $this->created[] = $token;

        $this->client->request('GET', '/preview/'.$token->getToken());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Ce document vous a été transmis personnellement', (string) $this->client->getResponse()->getContent());
    }

    /** The panel creates a link, revokes it, and refuses a link of another publication. */
    public function testTheBackOfficeCreatesAndRevokesLinks(): void
    {
        $post = $this->post('Audit '.$this->suffix);
        $other = $this->post('Autre '.$this->suffix);
        $foreign = $this->link($other);

        $this->client->loginUser($this->admin(), 'admin');

        $this->client->jsonRequest('POST', '/suite/editorial/posts/'.$post->getId().'/reading-links/create', [
            'label' => 'Envoyé à Marie',
            'expiresInDays' => 30,
            'password' => 'phrase-'.$this->suffix,
        ]);
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertCount(1, $data['links']);
        self::assertSame('Envoyé à Marie', $data['links'][0]['label']);
        self::assertTrue($data['links'][0]['locked']);
        self::assertNotNull($data['links'][0]['expiresAt']);
        self::assertTrue($data['readable']);

        $created = self::getContainer()->get(PostReadingLinkRepository::class)->find($data['links'][0]['id']);
        self::assertNotNull($created);
        $this->created[] = $created;
        // Hashed, never kept as typed.
        self::assertNotSame('phrase-'.$this->suffix, $created->getPasswordHash());

        $this->client->jsonRequest('POST', '/suite/editorial/posts/'.$post->getId().'/reading-links/'.$data['links'][0]['id'].'/revoke');
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertNotNull($data['links'][0]['revokedAt']);

        $this->client->jsonRequest('POST', '/suite/editorial/posts/'.$post->getId().'/reading-links/'.$foreign->getId().'/revoke');
        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->reload($foreign->getToken())->getRevokedAt());
    }

    private function assertKeptPrivate(string $page): void
    {
        $headers = $this->client->getResponse()->headers;

        self::assertSame('no-referrer', $headers->get('Referrer-Policy'), $page);
        self::assertStringContainsString('noindex', (string) $headers->get('X-Robots-Tag'), $page);
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'), $page);
        self::assertStringContainsString('private', (string) $headers->get('Cache-Control'), $page);
    }

    private function post(string $title, PostVisibilityEnum $visibility = PostVisibilityEnum::Link): Post
    {
        $type = self::getContainer()->get(PostTypeRepository::class)->findOneBySlug('page');
        self::assertInstanceOf(PostType::class, $type, 'the built-in page type is missing; run aurora:install');

        $post = new Post();
        $post->setPostType($type)
            ->setStatus(PostStatusEnum::Published)
            ->setVisibility($visibility)
            ->setPublishedAt(new DateTimeImmutable('-1 day'));
        $post->translate('fr')->setTitle($title)->setSlug('r-'.bin2hex(random_bytes(4)));

        $this->persist($post);

        return $post;
    }

    private function link(Post $post, ?string $password = null): PostReadingLink
    {
        $link = new PostReadingLink($post);

        if (null !== $password) {
            $link->setPasswordHash(password_hash($password, PASSWORD_DEFAULT));
        }

        $this->persist($link);

        return $link;
    }

    /**
     * The link as the database now holds it: the test client reboots the
     * kernel on every request, so an entity held across two is detached.
     */
    private function reload(string $token): PostReadingLink
    {
        $link = self::getContainer()->get(PostReadingLinkRepository::class)->findByToken($token);

        self::assertInstanceOf(PostReadingLink::class, $link);

        return $link;
    }

    private function admin(): User
    {
        $admin = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);

        return $admin;
    }

    private function persist(object $entity): void
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
        $this->created[] = $entity;
    }
}
