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
use function mb_substr_count;
use function random_bytes;
use function sprintf;

/**
 * The page of a section lists what its sub-sections hold.
 *
 * A publication is filed under a leaf of the taxonomy, never under the branch
 * above it. So the page of a section that only holds sub-sections answered 200
 * and listed nothing - an address a reader can legitimately reach, showing an
 * empty page that reads as broken rather than as empty.
 *
 * Found on the documentation, where the three parts of the work are terms with
 * no publication of their own.
 */
final class TermPageDescendantsTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    /** @var list<array{class-string, int}> */
    private array $created = [];

    private string $suffix;

    private Taxonomy $taxonomy;

    private PostType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->suffix = bin2hex(random_bytes(4));

        $this->type = new PostType();
        $this->type->setSlug('manuel-'.$this->suffix)->setLabel('Manuel')->setHasArchive(true);

        $this->taxonomy = new Taxonomy();
        $this->taxonomy->setSlug('partie-'.$this->suffix)->setHierarchical(true)->addPostType($this->type);
        $this->taxonomy->translate('fr')->setLabel('Parties');

        $this->entityManager->persist($this->type);
        $this->entityManager->persist($this->taxonomy);
        $this->entityManager->flush();

        $this->track($this->taxonomy);
        $this->track($this->type);
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

    public function testASectionListsThePagesOfItsSubSections(): void
    {
        $section = $this->term('Le back-office', 'back-office');
        $rubric = $this->term('Éditorial', 'editorial', $section);

        $this->page('La liste des publications', 'liste-des-publications', $rubric);
        $this->entityManager->flush();

        $html = $this->read('back-office');

        self::assertStringContainsString('La liste des publications', $html);
    }

    /** A term holding both its own publications and a sub-section's shows both. */
    public function testASectionAlsoKeepsItsOwnPages(): void
    {
        $section = $this->term('Le back-office', 'back-office');
        $rubric = $this->term('Éditorial', 'editorial', $section);

        $this->page('Introduction', 'introduction', $section);
        $this->page('La liste des publications', 'liste-des-publications', $rubric);
        $this->entityManager->flush();

        $html = $this->read('back-office');

        self::assertStringContainsString('Introduction', $html);
        self::assertStringContainsString('La liste des publications', $html);
    }

    /**
     * A publication filed under two terms of the same branch is listed once.
     *
     * The reason the query is DISTINCT: the join matches such a publication
     * twice, which would print it twice and count it twice in the pagination.
     */
    public function testAPageFiledTwiceInTheBranchIsListedOnce(): void
    {
        $section = $this->term('Le back-office', 'back-office');
        $rubric = $this->term('Éditorial', 'editorial', $section);

        $post = $this->page('La liste des publications', 'liste-des-publications', $rubric);
        $post->addTerm($section);

        $this->entityManager->flush();

        $html = $this->read('back-office');

        self::assertSame(1, mb_substr_count($html, 'La liste des publications'));
    }

    /** A leaf still lists its own pages, and only those. */
    public function testALeafIsUnchanged(): void
    {
        $section = $this->term('Le back-office', 'back-office');
        $rubric = $this->term('Éditorial', 'editorial', $section);
        $other = $this->term('Médiathèque', 'mediatheque', $section);

        $this->page('La liste des publications', 'liste-des-publications', $rubric);
        $this->page('Déposer un document', 'deposer-un-document', $other);
        $this->entityManager->flush();

        $html = $this->read('editorial');

        self::assertStringContainsString('La liste des publications', $html);
        self::assertStringNotContainsString('Déposer un document', $html);
    }

    private function read(string $termSlug): string
    {
        // The walk down the tree reads collections the controller loads from
        // the database; a manager still holding them from the writes above
        // would answer from memory and prove nothing.
        $this->entityManager->clear();

        $this->client->request('GET', sprintf('/fr/partie-%s/%s-%s', $this->suffix, $termSlug, $this->suffix));

        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    private function term(string $name, string $slug, ?TaxonomyTerm $parent = null): TaxonomyTerm
    {
        $term = new TaxonomyTerm();
        $term->setTaxonomy($this->taxonomy)->setPosition(1);

        if ($parent instanceof TaxonomyTerm) {
            $term->setParent($parent);
        }

        $term->translate('fr')->setName($name)->setSlug($slug.'-'.$this->suffix);

        $this->entityManager->persist($term);
        $this->entityManager->flush();
        $this->track($term);

        return $term;
    }

    private function page(string $title, string $slug, TaxonomyTerm $term): Post
    {
        $post = new Post();
        $post->setPostType($this->type)
            ->setStatus(PostStatusEnum::Published)
            ->setPublishedAt(new DateTimeImmutable('-1 day'))
            ->addTerm($term);

        $post->translate('fr')->setTitle($title)->setSlug($slug.'-'.$this->suffix);

        $this->entityManager->persist($post);
        $this->entityManager->flush();
        $this->track($post);

        return $post;
    }

    private function track(object $entity): void
    {
        $this->created[] = [$entity::class, (int) $entity->getId()];
    }
}
