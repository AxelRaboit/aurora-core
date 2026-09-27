<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Form\Entity\Form;
use Aurora\Module\Editorial\Form\Entity\FormField;
use Aurora\Module\Editorial\Form\Enum\FormFieldTypeEnum;
use Aurora\Module\Editorial\Post\Grid\ZoneSiteViews;
use Aurora\Module\Editorial\Taxonomy\Entity\Taxonomy;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTerm;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

use function array_filter;
use function array_reverse;
use function bin2hex;
use function count;
use function random_bytes;
use function str_contains;

/**
 * Two zones of a public page that read their translations in one go.
 *
 * A "terms" zone printed every term's name, and a form drawn inside a page
 * printed every field's label; both loaded those translations one row at a
 * time, on pages open to the world. The count is what regresses silently, so
 * it is what is held.
 */
final class ZoneSiteViewsQueriesTest extends IntegrationTestCase
{
    private EntityManagerInterface $entityManager;

    /** @var list<object> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
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

    public function testATermsZoneReadsItsTermsWithTheirNames(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $taxonomy = $this->entityManager->getRepository(Taxonomy::class)->findOneBy(['slug' => 'category']);
        self::assertNotNull($taxonomy, 'the built-in category taxonomy is missing; run aurora:install');

        foreach (['un', 'deux', 'trois'] as $name) {
            $term = new TaxonomyTerm();
            $term->setTaxonomy($taxonomy);
            $term->translate('fr')->setName('Rubrique '.$name)->setSlug('rubrique-'.$name.'-'.$suffix);
            $this->persist($term);
        }

        $this->entityManager->clear();
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $view = static::getContainer()->get(ZoneSiteViews::class)->termsView(['taxonomyId' => $taxonomy->getId()], 'fr');

        self::assertGreaterThanOrEqual(3, count($view['entries']));
        self::assertSame(1, $this->queriesOn($holder, 'core_taxonomy_term_translations'), 'the terms and their names load together');
    }

    public function testAFormInAPageReadsItsFieldsWithTheirLabels(): void
    {
        $form = new Form();
        $form->setActive(true);
        $form->translate('fr')->setTitle('Nous écrire')->setSlug('nous-ecrire-'.bin2hex(random_bytes(4)));

        $fields = [];
        foreach (['Votre nom', 'Votre e-mail', 'Votre message'] as $position => $label) {
            $field = new FormField();
            $form->addField($field);
            $field->setType(FormFieldTypeEnum::Text)->setRequired(false)->setPosition($position);
            $field->translate('fr')->setLabel($label);
            $fields[] = $field;
        }

        $this->persist($form);
        foreach ($fields as $field) {
            $this->entityManager->persist($field);
        }
        $this->entityManager->flush();
        foreach ($fields as $field) {
            $this->created[] = $field;
        }

        $this->entityManager->clear();
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $view = static::getContainer()->get(ZoneSiteViews::class)->formView((int) $form->getId(), 'fr');

        self::assertNotNull($view);
        self::assertSame('Nous écrire', $view['title']);
        self::assertSame(0, $this->queriesOn($holder, 'FROM core_form_field_translations'), 'the labels come with the form');
    }

    private function queriesOn(object $holder, string $needle): int
    {
        return count(array_filter(
            $holder->getData()['default'] ?? [],
            static fn (array $query): bool => str_contains((string) $query['sql'], $needle),
        ));
    }

    private function persist(object $entity): void
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
        $this->created[] = $entity;
    }
}
