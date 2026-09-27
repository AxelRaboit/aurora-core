<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Taxonomy;

use Aurora\Module\Editorial\Taxonomy\Entity\Taxonomy;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTerm;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function array_reverse;
use function bin2hex;
use function count;
use function random_bytes;
use function sprintf;

/**
 * A term's page costs the same queries however large its taxonomy.
 *
 * It found the term by loading every term of the taxonomy with its
 * translations, then gathered the sub-terms by asking each node for its
 * children, leaves included - on every address `/{locale}/{a}/{b}` that
 * matched no publication, 404s included.
 */
final class TermPageQueriesTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private Taxonomy $taxonomy;

    private TaxonomyTerm $section;

    private string $suffix;

    /** @var list<object> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->suffix = bin2hex(random_bytes(4));

        $this->taxonomy = new Taxonomy();
        $this->taxonomy->setSlug('rubrique-'.$this->suffix)->setHierarchical(true);
        $this->taxonomy->translate('fr')->setLabel('Rubriques');
        $this->persist($this->taxonomy);

        $this->section = $this->term('Section', null);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $entity) {
            $managed = $this->entityManager->find($entity::class, $entity->getId());
            if (null !== $managed) {
                $this->entityManager->remove($managed);
            }
        }

        $this->entityManager->flush();
        parent::tearDown();
    }

    public function testThePageDoesNotGrowWithTheTaxonomy(): void
    {
        foreach (['Un', 'Deux', 'Trois'] as $name) {
            $this->term($name, $this->section);
        }
        // Once to warm what the services keep between requests - locales,
        // theme, menus - so the two counts compare the page and nothing else.
        $this->queriesFor(sprintf('/fr/rubrique-%s/section-%s', $this->suffix, $this->suffix));
        $withThree = $this->queriesFor(sprintf('/fr/rubrique-%s/section-%s', $this->suffix, $this->suffix));

        foreach (['Quatre', 'Cinq', 'Six'] as $name) {
            $this->term($name, $this->section);
        }
        $withSix = $this->queriesFor(sprintf('/fr/rubrique-%s/section-%s', $this->suffix, $this->suffix));

        self::assertSame($withThree, $withSix, 'three more sub-terms, not one more query');
    }

    public function testAnUnknownTermIsAnsweredWithoutWalkingTheTaxonomy(): void
    {
        foreach (['Un', 'Deux', 'Trois'] as $name) {
            $this->term($name, $this->section);
        }
        $this->queriesFor(sprintf('/fr/rubrique-%s/inconnu', $this->suffix), 404);
        $withThree = $this->queriesFor(sprintf('/fr/rubrique-%s/inconnu', $this->suffix), 404);

        foreach (['Quatre', 'Cinq', 'Six'] as $name) {
            $this->term($name, $this->section);
        }

        self::assertSame($withThree, $this->queriesFor(sprintf('/fr/rubrique-%s/inconnu', $this->suffix), 404));
    }

    private function queriesFor(string $path, int $status = 200): int
    {
        $this->entityManager->clear();
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $this->client->request('GET', $path);
        self::assertResponseStatusCodeSame($status);
        $count = count($holder->getData()['default'] ?? []);

        $this->taxonomy = $this->entityManager->find(Taxonomy::class, $this->taxonomy->getId());
        $this->section = $this->entityManager->find(TaxonomyTerm::class, $this->section->getId());

        return $count;
    }

    private function term(string $name, ?TaxonomyTerm $parent): TaxonomyTerm
    {
        $term = new TaxonomyTerm();
        $term->setTaxonomy($this->taxonomy)->setParent($parent)->setPosition(count($this->created));
        $term->translate('fr')->setName($name)->setSlug(sprintf('%s-%s', mb_strtolower($name), $this->suffix));
        $this->persist($term);

        return $term;
    }

    private function persist(object $entity): void
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
        $this->created[] = $entity;
    }
}
