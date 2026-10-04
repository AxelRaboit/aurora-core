<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Module\Editorial\Taxonomy\Entity\Taxonomy;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTerm;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function bin2hex;
use function random_bytes;
use function sprintf;

/**
 * Two types of content may hold the same address.
 *
 * The public route is `/{locale}/{postTypeSlug}/{slug}` and the lookup ignored
 * the type: the first publication with that slug won, whatever type it
 * belonged to. Where the two disagreed, the controller took it for a
 * publication that had changed type and answered a **permanent** redirect to
 * the other one - so the page asked for became unreachable, and browsers
 * cached the detour.
 *
 * It surfaced on the documentation, where a page called "tableau-de-bord" was
 * about to be written beside a card of the tour with exactly that address.
 * Both are legitimate, and neither is the other.
 *
 * The redirect itself has to survive: an address shared before a publication
 * changed type must still lead somewhere. So it stays, as the fall-back it
 * always meant to be.
 */
final class PostSlugScopeTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    /** @var list<array{class-string, int}> */
    private array $created = [];

    private string $slug;

    private PostType $first;

    private PostType $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(4));
        $this->slug = 'tableau-de-bord-'.$suffix;
        $this->first = $this->type('tour-'.$suffix);
        $this->second = $this->type('manuel-'.$suffix);
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

    /** Each address answers its own page, with no redirect between them. */
    public function testEachTypeAnswersItsOwnPage(): void
    {
        $this->publish($this->first, 'La carte du tour');
        $this->publish($this->second, 'La page du manuel');

        foreach ([[$this->first, 'La carte du tour'], [$this->second, 'La page du manuel']] as [$type, $title]) {
            $this->client->request('GET', sprintf('/fr/%s/%s', $type->getSlug(), $this->slug));

            self::assertResponseIsSuccessful();
            self::assertStringContainsString($title, (string) $this->client->getResponse()->getContent());
        }
    }

    /**
     * The behaviour that had to survive the fix.
     *
     * An address shared before a publication changed type still leads to it,
     * by a permanent redirect - which is only reached now when no publication
     * of the named type answers.
     */
    public function testAnAddressUnderTheWrongTypeStillRedirects(): void
    {
        $this->publish($this->first, 'La carte du tour');

        $this->client->request('GET', sprintf('/fr/%s/%s', $this->second->getSlug(), $this->slug));

        self::assertResponseRedirects(sprintf('/fr/%s/%s', $this->first->getSlug(), $this->slug), 301);
    }

    /**
     * A taxonomy page is not a publication that shares its address.
     *
     * `/{locale}/{a}/{b}` is read as a publication first, and only falls
     * through to the term page when no publication answers. The wider of the
     * two look-ups - the one ignoring the type, kept so an address shared
     * before a publication changed type still leads somewhere - ran before
     * that fall-through, so any publication anywhere carrying the term's slug
     * captured the term's page and answered a **permanent** redirect to
     * itself. The term page became unreachable, and browsers cached the
     * detour.
     *
     * Found on the documentation: the rubric `site-public` and a card of the
     * tour with that address are both legitimate, and neither is the other.
     */
    public function testATermPageIsNotCapturedByAPublicationSharingItsSlug(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $taxonomySlug = 'section-'.$suffix;

        $taxonomy = new Taxonomy();
        $taxonomy->setSlug($taxonomySlug)->setHierarchical(true)->addPostType($this->first);
        $taxonomy->translate('fr')->setLabel('Rubriques');

        $term = new TaxonomyTerm();
        $term->setTaxonomy($taxonomy)->setPosition(1);
        $term->translate('fr')->setName('Le site public')->setSlug($this->slug);

        $this->entityManager->persist($taxonomy);
        $this->entityManager->persist($term);
        $this->entityManager->flush();

        $this->created[] = [$term::class, (int) $term->getId()];
        $this->created[] = [$taxonomy::class, (int) $taxonomy->getId()];

        // The collision: a publication of an unrelated type, carrying the
        // address the term also answers to.
        $this->publish($this->second, 'La carte du tour');

        // The controller walks the taxonomy's terms; a manager still holding
        // the ones written above would answer from memory, and the collection
        // it holds was never told about the term.
        $this->entityManager->clear();

        $this->client->request('GET', sprintf('/fr/%s/%s', $taxonomySlug, $this->slug));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Le site public', (string) $this->client->getResponse()->getContent());
    }

    private function type(string $slug): PostType
    {
        $type = new PostType();
        $type->setSlug($slug)->setLabel($slug)->setHasArchive(true);

        $this->entityManager->persist($type);
        $this->entityManager->flush();
        $this->created[] = [$type::class, (int) $type->getId()];

        return $type;
    }

    private function publish(PostType $type, string $title): void
    {
        $post = new Post();
        $post->setPostType($type)
            ->setStatus(PostStatusEnum::Published)
            ->setPublishedAt(new DateTimeImmutable('-1 day'));

        $post->translate('fr')->setTitle($title)->setSlug($this->slug);

        $this->entityManager->persist($post);
        $this->entityManager->flush();
        $this->created[] = [$post::class, (int) $post->getId()];
    }
}
