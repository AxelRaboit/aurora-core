<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Dto\PostInputFactoryInterface;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Manager\PostManagerInterface;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Module\Editorial\Taxonomy\Entity\Taxonomy;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTerm;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function array_reverse;
use function mb_substr_count;

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
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
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

        parent::tearDown();
    }

    public function testAPageHasNoUsefulLinksByDefault(): void
    {
        $post = $this->published('Page sans liens', []);

        self::assertFalse($post->isUsefulLinksEnabled());
        self::assertSame([], $post->getUsefulLinks());

        $this->client->request('GET', '/fr/links-type/page-sans-liens');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Liens utiles', (string) $this->client->getResponse()->getContent());
    }

    /**
     * The links reach the page in order with their colour, sit in the same
     * footer as the share row under a single rule, and an address that is
     * not https or mailto never gets that far.
     */
    public function testTickedLinksReachThePageAndBadAddressesDoNot(): void
    {
        $this->published('Page avec liens', [
            'usefulLinksEnabled' => true,
            'usefulLinks' => [
                ['label' => 'Le code', 'url' => 'https://github.com/AxelRaboit/aurora-core', 'color' => '#34d399'],
                ['label' => 'Piège', 'url' => 'javascript:alert(1)'],
                ['label' => 'Écrire', 'url' => 'mailto:hello@example.com'],
            ],
        ]);

        $this->client->request('GET', '/fr/links-type/page-avec-liens');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Liens utiles', $html);
        self::assertStringContainsString('href="https://github.com/AxelRaboit/aurora-core"', $html);
        self::assertStringContainsString('color: #34d399;', $html);
        self::assertStringContainsString('href="mailto:hello@example.com"', $html);
        self::assertStringNotContainsString('javascript:alert', $html);
        self::assertStringNotContainsString('Piège', $html);
        // Links and share buttons share one footer: one rule, not two.
        self::assertSame(1, mb_substr_count($html, 'not-prose mt-8 space-y-3 border-t border-line pt-4'));
        self::assertStringContainsString('ShareButtons', $html);
    }

    /** Unticking the box hides the links without forgetting them. */
    public function testAnUntickedBoxKeepsTheLinksButDoesNotShowThem(): void
    {
        $post = $this->published('Page liens cachés', [
            'usefulLinksEnabled' => false,
            'usefulLinks' => [['label' => 'Le code', 'url' => 'https://github.com/AxelRaboit']],
        ]);

        self::assertCount(1, $post->getUsefulLinks());

        $this->client->request('GET', '/fr/links-type/page-liens-caches');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('https://github.com/AxelRaboit"', (string) $this->client->getResponse()->getContent());
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

        $post = static::getContainer()->get(PostManagerInterface::class)->create(
            static::getContainer()->get(PostInputFactoryInterface::class)->fromArray([
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
