<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\Post\Sequence\PostSequenceBuilder;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Module\Editorial\Taxonomy\Entity\Taxonomy;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTerm;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function bin2hex;
use function json_decode;
use function random_bytes;
use function sprintf;
use function urlencode;

use const JSON_THROW_ON_ERROR;

/**
 * The summary beside a page, and the way to the next one.
 *
 * What separates a documentation from a list of articles. It hangs off three
 * things that already existed and were never used together: a hierarchical
 * taxonomy for the tree, a reading position for the order, and the `sequence`
 * support on the type for the switch.
 *
 * The switch is the part worth testing hardest. Every other type of content on
 * a site renders through the same template, so a summary that leaked onto an
 * article would be the defect this feature introduces rather than fixes.
 */
final class PostSequenceTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private PostType $type;

    private TaxonomyTerm $rubric;

    /** @var list<array{class-string, int}> the summary clears the manager, so tidy-up refetches */
    private array $created = [];

    private string $suffix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->suffix = bin2hex(random_bytes(4));

        $this->type = new PostType();
        $this->type->setSlug('guide-'.$this->suffix)
            ->setLabel('Guide')
            ->setHasArchive(true)
            ->setSupports(['blocks', 'sequence']);

        $this->entityManager->persist($this->type);

        $taxonomy = new Taxonomy();
        $taxonomy->setSlug('partie-'.$this->suffix)->setHierarchical(true)->addPostType($this->type);
        $taxonomy->translate('fr')->setLabel('Parties');

        $this->entityManager->persist($taxonomy);

        $section = new TaxonomyTerm();
        $section->setTaxonomy($taxonomy)->setPosition(1);
        $section->translate('fr')->setName('Pour commencer')->setSlug('pour-commencer-'.$this->suffix);

        $this->rubric = new TaxonomyTerm();
        $this->rubric->setTaxonomy($taxonomy)->setParent($section)->setPosition(1);
        $this->rubric->translate('fr')->setName('Les bases')->setSlug('les-bases-'.$this->suffix);

        $this->entityManager->persist($section);
        $this->entityManager->persist($this->rubric);
        $this->entityManager->flush();

        $this->track($this->rubric);
        $this->track($section);
        $this->track($taxonomy);
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

    private function track(object $entity): void
    {
        $this->created[] = [$entity::class, (int) $entity->getId()];
    }

    public function testTheMiddlePageShowsBothNeighbours(): void
    {
        $this->page('Premier', 1);
        $this->page('Deuxième', 2);
        $this->page('Troisième', 3);
        $this->entityManager->flush();

        $html = $this->read('deuxieme');

        self::assertStringContainsString(sprintf('href="/fr/guide-%s/premier"', $this->suffix), $html);
        self::assertStringContainsString('rel="prev"', $html);
        self::assertStringContainsString(sprintf('href="/fr/guide-%s/troisieme"', $this->suffix), $html);
        self::assertStringContainsString('rel="next"', $html);
    }

    /**
     * The summary costs the same queries for three pages or six.
     *
     * It loaded each page's rubrics, then each rubric's name, one at a time:
     * some sixty queries on a documentation page, on every page of it.
     */
    public function testTheSummaryDoesNotGrowWithItsPages(): void
    {
        $first = $this->page('Un', 1);
        $this->page('Deux', 2);
        $this->page('Trois', 3);
        $withThree = $this->queriesToBuild((int) $first->getId());

        $this->page('Quatre', 4);
        $this->page('Cinq', 5);
        $this->page('Six', 6);
        $withSix = $this->queriesToBuild((int) $first->getId());

        self::assertSame($withThree, $withSix, 'three more pages, not one more query');
    }

    /** The ends of the sequence lead nowhere rather than wrapping around. */
    public function testTheFirstPageHasNoPrevious(): void
    {
        $this->page('Premier', 1);
        $this->page('Deuxième', 2);
        $this->entityManager->flush();

        $html = $this->read('premier');

        self::assertStringNotContainsString('rel="prev"', $html);
        self::assertStringContainsString('rel="next"', $html);
    }

    public function testTheLastPageHasNoNext(): void
    {
        $this->page('Premier', 1);
        $this->page('Deuxième', 2);
        $this->entityManager->flush();

        $html = $this->read('deuxieme');

        self::assertStringContainsString('rel="prev"', $html);
        self::assertStringNotContainsString('rel="next"', $html);
    }

    /** The summary names the tree, and marks where the reader is. */
    public function testTheSummaryListsTheRubricAndMarksTheCurrentPage(): void
    {
        $this->page('Premier', 1);
        $this->page('Deuxième', 2);
        $this->entityManager->flush();

        $html = $this->read('premier');

        // The nav, not the words: the rubric's name also appears in the term
        // chips at the foot of every page, so matching it alone would pass
        // with no summary at all.
        self::assertStringContainsString('aria-label="Sommaire"', $html);
        self::assertStringContainsString('Pour commencer', $html);
        self::assertStringContainsString('aria-current="page"', $html);
    }

    /**
     * The switch, which is the whole reason `sequence` exists as a support.
     *
     * Every type of content renders through this template. A summary that
     * appeared under an article would be a regression introduced by a feature
     * meant for one type.
     */
    public function testATypeThatIsNotReadInSequenceGetsNothing(): void
    {
        $this->type->setSupports(['blocks']);
        $this->page('Premier', 1);
        $this->page('Deuxième', 2);
        $this->entityManager->flush();

        $html = $this->read('premier');

        self::assertStringNotContainsString('rel="next"', $html);
        self::assertStringNotContainsString('aria-label="Sommaire"', $html);
    }

    /**
     * The search of the documentation, scoped to its own type.
     *
     * A reader looks something up rather than browsing a hundred and thirty
     * links; the summary answers "what is there" and this answers "where is
     * the page that mentions it". Scoped to the type, because an answer from
     * the blog would be an answer beside the question.
     */
    public function testTheSummaryCarriesTheSearchOfItsOwnType(): void
    {
        $this->page('Premier', 1);
        $this->entityManager->flush();

        $html = $this->read('premier');

        self::assertStringContainsString('SequenceSearch', $html);
        self::assertStringContainsString(sprintf('type=guide-%s', $this->suffix), $html);
    }

    /**
     * A section whose rubrics hold no visible page used to print its own
     * label followed by nothing. It happens as soon as a term exists without
     * a published page under it - a rubric emptied, or created before
     * anything is written in it - and a heading with no list under it reads
     * as a broken page.
     */
    public function testASectionWithNoPageIsNotDrawn(): void
    {
        $empty = new TaxonomyTerm();
        $empty->setTaxonomy($this->rubric->getTaxonomy())->setPosition(2);
        $empty->translate('fr')->setName('Pour finir')->setSlug('pour-finir-'.$this->suffix);

        $this->entityManager->persist($empty);

        $this->page('Premier', 1);
        $this->entityManager->flush();
        $this->track($empty);

        $html = $this->read('premier');

        self::assertStringContainsString('Pour commencer', $html, 'la section qui porte une page a disparu');
        self::assertStringNotContainsString('Pour finir', $html);
    }

    /**
     * The endpoint behind the field. Scoped by `type`, and searching the text
     * of the pages rather than their titles alone: a reader who remembers a
     * word from a paragraph has no reason to remember which heading it sat
     * under.
     */
    public function testTheSearchAnswersWithinItsTypeOnly(): void
    {
        $mine = $this->page('Premier', 1);
        // `search_content` is what the full-text index reads, and the manager
        // fills it from the page's own words on save. Written by hand here
        // because this test creates the page directly: what is under test is
        // the scope of the search, not the extractor.
        $mine->getTranslation('fr')?->setSearchContent('Une histoire de girafes rousses.');
        $this->entityManager->flush();

        $found = $this->searchJson('girafes', 'guide-'.$this->suffix);

        self::assertCount(1, $found['posts']);
        self::assertSame('Premier', $found['posts'][0]['title']);

        // The same word asked of the default type, which holds no such page.
        self::assertSame([], $this->searchJson('girafes', null)['posts']);
    }

    /** A type that does not exist is a wrong address, not an empty answer. */
    public function testAnUnknownTypeIsRefused(): void
    {
        $this->client->request('GET', '/fr/search?q=quoi&type=ce-type-n-existe-pas');

        self::assertResponseStatusCodeSame(404);
    }

    /** @return array{posts: list<array<string, mixed>>} */
    private function searchJson(string $query, ?string $type): array
    {
        $this->entityManager->clear();

        $url = sprintf('/fr/search?q=%s', urlencode($query));

        if (null !== $type) {
            $url .= '&type='.urlencode($type);
        }

        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();

        /** @var array{posts: list<array<string, mixed>>} $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    private function queriesToBuild(int $postId): int
    {
        $this->entityManager->clear();
        $post = $this->entityManager->find(Post::class, $postId);
        self::assertInstanceOf(Post::class, $post);

        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $sequence = static::getContainer()->get(PostSequenceBuilder::class)->build($post, 'fr');
        self::assertNotNull($sequence);
        $queries = count($holder->getData()['default'] ?? []);

        // Managed again, so the next pages can hang off them.
        $this->type = $this->entityManager->find(PostType::class, $this->type->getId());
        $this->rubric = $this->entityManager->find(TaxonomyTerm::class, $this->rubric->getId());

        return $queries;
    }

    private function read(string $slug): string
    {
        // The summary walks collections the controller loads from the
        // database; a manager still holding them from the writes above would
        // answer from memory and prove nothing.
        $this->entityManager->clear();

        $this->client->request('GET', sprintf('/fr/guide-%s/%s', $this->suffix, $slug));

        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    private function page(string $title, int $position): Post
    {
        $post = new Post();
        $post->setPostType($this->type)
            ->setStatus(PostStatusEnum::Published)
            ->setPublishedAt(new DateTimeImmutable('-1 day'))
            ->setPosition($position)
            ->addTerm($this->rubric);

        $post->translate('fr')->setTitle($title)->setSlug(mb_strtolower(strtr($title, ['è' => 'e', 'é' => 'e'])));

        $this->entityManager->persist($post);
        $this->entityManager->flush();
        $this->track($post);

        return $post;
    }
}
