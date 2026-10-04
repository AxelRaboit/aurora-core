<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Editorial\Post\Dto\PostInputFactoryInterface;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Manager\PostManagerInterface;
use Aurora\Module\Editorial\Post\Share\SiteUsefulLinks;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Module\Editorial\Taxonomy\Entity\Taxonomy;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTerm;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function array_reverse;
use function json_decode;
use function json_encode;
use function mb_substr_count;

use const JSON_THROW_ON_ERROR;

/**
 * The useful links at the foot of a page, from the editor's box to the
 * served HTML. Off by default: no page had them before the box existed.
 */
final class PostUsefulLinksTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    /** @var list<array{class-string, int}> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as [$class, $id]) {
            $entity = $this->entityManager->find($class, $id);
            if (null !== $entity) {
                $this->entityManager->remove($entity);
            }
        }

        $this->entityManager->flush();
        $this->created = [];
        self::getContainer()->get(SettingRepository::class)->set(SiteUsefulLinks::KEY, null);

        parent::tearDown();
    }

    /** On by default, and following the site's list, which starts empty. */
    public function testAPageFollowsTheSiteByDefaultAndShowsNothingUntilTheSiteHasLinks(): void
    {
        $post = $this->published('Page sans liens', []);

        self::assertTrue($post->isUsefulLinksEnabled());
        self::assertNull($post->getUsefulLinks());

        $this->client->request('GET', '/fr/links-type/page-sans-liens');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Liens utiles', (string) $this->client->getResponse()->getContent());
    }

    /** Set once in Configuration, the site's links reach a page that has none of its own. */
    public function testTheSiteLinksReachEveryPageThatFollowsThem(): void
    {
        $this->saveSiteLinks([
            ['label' => 'Le code', 'url' => 'https://github.com/AxelRaboit', 'color' => '#34d399'],
            ['label' => 'Piège', 'url' => 'javascript:alert(1)'],
        ]);
        $this->published('Page du site', []);

        $this->client->request('GET', '/fr/links-type/page-du-site');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Liens utiles', $html);
        self::assertStringContainsString('href="https://github.com/AxelRaboit"', $html);
        self::assertStringContainsString('color: #34d399;', $html);
        self::assertStringNotContainsString('javascript:alert', $html);
    }

    /**
     * A page's own links win over the site's, reach the page in order with
     * their colour, and sit in the same footer as the share row under a
     * single rule; an address that is not https or mailto never gets that far.
     */
    public function testAPagesOwnLinksWinOverTheSites(): void
    {
        $this->saveSiteLinks([['label' => 'Lien du site', 'url' => 'https://example.com/site']]);
        $this->published('Page avec liens', [
            'usefulLinks' => [
                ['label' => 'Le code', 'url' => 'https://github.com/AxelRaboit/aurora-core', 'color' => '#34d399'],
                ['label' => 'Piège', 'url' => 'javascript:alert(1)'],
                ['label' => 'Écrire', 'url' => 'mailto:hello@example.com'],
            ],
        ]);

        $this->client->request('GET', '/fr/links-type/page-avec-liens');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('href="https://github.com/AxelRaboit/aurora-core"', $html);
        self::assertStringContainsString('href="mailto:hello@example.com"', $html);
        self::assertStringNotContainsString('Lien du site', $html);
        self::assertStringNotContainsString('Piège', $html);
        // Links and share buttons share one footer: one rule, not two.
        self::assertSame(1, mb_substr_count($html, 'not-prose mt-8 space-y-3 border-t border-line pt-4'));
        self::assertStringContainsString('ShareButtons', $html);
    }

    /** The Configuration tab keeps what it may and says what it kept. */
    public function testTheConfigurationTabKeepsOnlyUsableLinks(): void
    {
        $admin = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');

        $this->client->request('POST', '/backend/editorial/useful-links/settings', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode(['links' => [
            ['label' => 'GitHub', 'url' => 'https://github.com/AxelRaboit'],
            ['label' => '', 'url' => 'https://example.com'],
            ['label' => 'Piège', 'url' => 'javascript:alert(1)'],
        ]], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame([['label' => 'GitHub', 'url' => 'https://github.com/AxelRaboit', 'color' => null]], $answer['links'] ?? null);
        self::assertSame($answer['links'], self::getContainer()->get(SiteUsefulLinks::class)->links());
    }

    /** Unticking the box hides the links without forgetting them. */
    public function testAnUntickedBoxKeepsTheLinksButDoesNotShowThem(): void
    {
        $this->saveSiteLinks([['label' => 'Lien du site', 'url' => 'https://example.com/site']]);
        $post = $this->published('Page liens cachés', [
            'usefulLinksEnabled' => false,
            'usefulLinks' => [['label' => 'Le code', 'url' => 'https://github.com/AxelRaboit']],
        ]);

        self::assertCount(1, $post->getUsefulLinks());

        $this->client->request('GET', '/fr/links-type/page-liens-caches');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('https://github.com/AxelRaboit"', $html);
        self::assertStringNotContainsString('Lien du site', $html);
    }

    /**
     * The chips at the foot of the page say what they are, in the page's
     * language, and say nothing rather than the wrong language.
     */
    public function testTermsOpenWithTheirTaxonomyName(): void
    {
        $taxonomy = new Taxonomy();
        $taxonomy->setSlug('competences-test');
        $taxonomy->translate('fr')->setLabel('Compétences');
        $this->entityManager->persist($taxonomy);
        $this->entityManager->persist($taxonomy->translate('fr'));

        $term = new TaxonomyTerm();
        $term->setTaxonomy($taxonomy);
        $term->setPosition(0);

        $this->entityManager->persist($term);
        foreach (['fr', 'en'] as $locale) {
            $translation = $term->translate($locale);
            $translation->setName('Community management');
            $translation->setSlug('community-management-'.$locale);
            $this->entityManager->persist($translation);
        }

        $this->entityManager->flush();
        $this->created[] = [Taxonomy::class, (int) $taxonomy->getId()];

        $this->published('Page classée', [
            'termIds' => [(int) $term->getId()],
            'translations' => ['fr' => ['title' => 'Page classée'], 'en' => ['title' => 'Filed page']],
        ]);

        $this->client->request('GET', '/fr/links-type/page-classee');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<span class="text-sm text-secondary">Compétences</span>', $html);
        self::assertStringContainsString('Community management', $html);

        // No English name for the taxonomy: the chip stands alone.
        $this->client->request('GET', '/en/links-type/filed-page');
        self::assertResponseIsSuccessful();
        $english = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Community management', $english);
        self::assertStringNotContainsString('Compétences', $english);
    }

    /** @param list<array<string, mixed>> $links */
    private function saveSiteLinks(array $links): void
    {
        self::getContainer()->get(SiteUsefulLinks::class)->save($links);
    }

    /** @param array<string, mixed> $fields */
    private function published(string $title, array $fields): PostInterface
    {
        $postType = $this->entityManager->getRepository(PostType::class)->findOneBy(['slug' => 'links-type']);

        if (!$postType instanceof PostType) {
            $postType = new PostType();
            $postType->setSlug('links-type');
            $postType->setLabel('Links type');
            $this->entityManager->persist($postType);
            $this->entityManager->flush();
            $this->created[] = [PostType::class, (int) $postType->getId()];
        }

        $post = self::getContainer()->get(PostManagerInterface::class)->create(
            self::getContainer()->get(PostInputFactoryInterface::class)->fromArray([
                'postTypeId' => $postType->getId(),
                'status' => 'published',
                'translations' => ['fr' => ['title' => $title]],
                ...$fields,
            ]),
        );
        $this->entityManager->flush();
        $this->created[] = [Post::class, (int) $post->getId()];

        return $post;
    }
}
